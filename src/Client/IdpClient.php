<?php

declare(strict_types=1);

namespace Amtgard\IdpClient\Client;

use Amtgard\IAM\Allowance\Policy;
use Amtgard\IAM\Requirement\Requirement;
use Amtgard\IdpClient\ClientIam\ClientIamClient;
use Amtgard\IdpClient\ClientIam\Http\Psr18ClientIamHttpClient;
use Amtgard\IdpClient\Config\IdpClientEnvironment;
use Amtgard\IdpClient\Exception\ErrorCode;
use Amtgard\IdpClient\Exception\InvalidOAuthStateException;
use Amtgard\IdpClient\Exception\ResourceException;
use Amtgard\IdpClient\Exception\TokenExchangeException;
use Amtgard\IdpClient\Iam\AuthorizationCheck;
use Amtgard\IdpClient\Iam\AuthorizationEvaluator;
use Amtgard\IdpClient\Iam\OrnParser;
use Amtgard\IdpClient\OAuth\AuthorizationResult;
use Amtgard\IdpClient\OAuth\Http\IdpTokenClient;
use Amtgard\IdpClient\OAuth\IdpProvider;
use Amtgard\IdpClient\OAuth\OAuthFlowState;
use Amtgard\IdpClient\OAuth\OAuthFlowStateStore;
use Amtgard\IdpClient\OAuth\Pkce;
use Amtgard\IdpClient\OAuth\TokenSet;
use Amtgard\IdpClient\Resource\AuthenticatedSession;
use Amtgard\IdpClient\Resource\Http\IdpHttpCookies;
use Amtgard\IdpClient\Resource\Http\Psr18IdpHttpClient;
use Amtgard\IdpClient\Resource\UserProfile;
use Amtgard\IdpClient\Resource\ValidatedSession;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\StreamFactoryInterface;

final class IdpClient
{
    private readonly IdpProvider $provider;
    private readonly IdpTokenClient $tokenClient;
    private readonly Psr18IdpHttpClient $resourceClient;
    private readonly AuthorizationEvaluator $authorizationEvaluator;
    private readonly OrnParser $ornParser;
    private ?ClientIamClient $clientIam = null;

    public function __construct(
        private readonly IdpClientEnvironment $environment,
        private readonly OAuthFlowStateStore $flowState,
        private readonly ClientInterface $http,
        private readonly RequestFactoryInterface $requests,
        private readonly ResponseFactoryInterface $responses,
        ?IdpProvider $provider = null,
    ) {
        $this->provider = $provider ?? self::createDefaultProvider($environment, $http);
        $streams = $requests instanceof StreamFactoryInterface
            ? $requests
            : new \Nyholm\Psr7\Factory\Psr17Factory();
        $this->tokenClient = new IdpTokenClient($environment, $http, $requests, $streams);
        $this->resourceClient = new Psr18IdpHttpClient($environment, $http, $requests, $streams);
        $this->authorizationEvaluator = new AuthorizationEvaluator();
        $this->ornParser = new OrnParser();
    }

    public function beginAuthorization(?string $returnTo = null): ResponseInterface
    {
        $codeVerifier = Pkce::generateVerifier();
        $codeChallenge = Pkce::challengeFromVerifier($codeVerifier);
        $state = Pkce::generateState();

        $this->flowState->put(new OAuthFlowState($state, $codeVerifier, $returnTo));

        $authorizationUrl = $this->provider->getAuthorizationUrl([
            'state' => $state,
            'code_challenge' => $codeChallenge,
            'code_challenge_method' => 'S256',
        ]);

        return $this->responses
            ->createResponse(302)
            ->withHeader('Location', $authorizationUrl);
    }

    public function completeAuthorization(ServerRequestInterface $callbackRequest): AuthorizationResult
    {
        $query = $callbackRequest->getQueryParams();

        if (isset($query['error'])) {
            $error = is_string($query['error']) ? $query['error'] : 'unknown';
            $description = isset($query['error_description']) && is_string($query['error_description'])
                ? $query['error_description']
                : null;

            throw new InvalidOAuthStateException(
                ErrorCode::OAuthCallbackError,
                sprintf(
                    'IDP returned OAuth error "%s"%s. %s',
                    $error,
                    $description !== null ? " ({$description})" : '',
                    sprintf('See README %s for fix instructions.', ErrorCode::OAuthCallbackError->readmeAnchor()),
                ),
                $error,
                $description,
            );
        }

        $callbackState = $query['state'] ?? null;
        if (!is_string($callbackState) || $callbackState === '') {
            throw new InvalidOAuthStateException(
                ErrorCode::StateParamMissing,
                sprintf(
                    'OAuth callback is missing the state query parameter. %s',
                    sprintf('See README %s for fix instructions.', ErrorCode::StateParamMissing->readmeAnchor()),
                ),
            );
        }

        $stored = $this->flowState->pull();
        if ($stored === null) {
            throw new InvalidOAuthStateException(
                ErrorCode::FlowStateMissing,
                sprintf(
                    'OAuth flow state was not found in the configured store (session expired or beginAuthorization() was not called). %s',
                    sprintf('See README %s for fix instructions.', ErrorCode::FlowStateMissing->readmeAnchor()),
                ),
            );
        }

        if (!hash_equals($stored->state, $callbackState)) {
            throw new InvalidOAuthStateException(
                ErrorCode::StateMismatch,
                sprintf(
                    'OAuth state mismatch (possible CSRF or multiple parallel login attempts). %s',
                    sprintf('See README %s for fix instructions.', ErrorCode::StateMismatch->readmeAnchor()),
                ),
            );
        }

        $code = $query['code'] ?? null;
        if (!is_string($code) || $code === '') {
            throw new InvalidOAuthStateException(
                ErrorCode::AuthCodeMissing,
                sprintf(
                    'OAuth callback is missing the authorization code. %s',
                    sprintf('See README %s for fix instructions.', ErrorCode::AuthCodeMissing->readmeAnchor()),
                ),
            );
        }

        $tokens = $this->tokenClient->exchangeAuthorizationCode($code, $stored->codeVerifier);

        return new AuthorizationResult($tokens, $stored->returnTo);
    }

    public function completeLogin(ServerRequestInterface $callbackRequest): AuthenticatedSession
    {
        $result = $this->completeAuthorization($callbackRequest);
        $cookies = new IdpHttpCookies();

        return new AuthenticatedSession(
            $result->tokens,
            $this->fetchUserProfileForAccessToken($result->tokens->accessToken(), $cookies),
            $result->returnTo,
            $cookies->toHeader(),
        );
    }

    /**
     * Load the full user profile for an OAuth access token.
     *
     * Prefers the documented elevation flow ({@see fetchJwt()} then {@see fetchUserProfile()}).
     * If the IDP rejects elevation at `/resources/jwt`, falls back to calling `/resources/userinfo`
     * with the access token directly (supported on some deployed IDP builds).
     */
    public function fetchUserProfileForAccessToken(string $oauthAccessToken, ?IdpHttpCookies $cookies = null): UserProfile
    {
        return $this->fetchUserProfile($this->resolveResourceBearer($oauthAccessToken, $cookies), $cookies);
    }

    /**
     * Session heartbeat for an OAuth access token.
     *
     * Calls {@see fetchUserProfile()} then {@see validate()} with the same bearer token so the IDP
     * Redis cache (keyed on the userinfo Authorization header) matches the validate challenge JWT.
     */
    public function validateForAccessToken(string $oauthAccessToken, ?IdpHttpCookies $cookies = null): ValidatedSession
    {
        $bearer = $this->resolveResourceBearer($oauthAccessToken, $cookies);
        $this->fetchUserProfile($bearer, $cookies);

        return $this->validate($bearer, $cookies);
    }

    /**
     * @param string $authorizationJwt RS256 authorization JWT from {@see fetchJwt()} or {@see UserProfile::$jwt}
     */
    public function fetchUserProfile(string $authorizationJwt, ?IdpHttpCookies $cookies = null): UserProfile
    {
        return $this->resourceClient->fetchUserProfile($authorizationJwt, $cookies);
    }

    /**
     * @param string $authorizationJwt RS256 authorization JWT from {@see fetchJwt()} or {@see UserProfile::$jwt}
     */
    public function validate(string $authorizationJwt, ?IdpHttpCookies $cookies = null): ValidatedSession
    {
        return $this->resourceClient->validate($authorizationJwt, $cookies);
    }

    /**
     * @param string $oauthAccessToken OAuth access token from {@see TokenSet::accessToken()}
     */
    public function fetchJwt(string $oauthAccessToken, ?IdpHttpCookies $cookies = null): string
    {
        return $this->resourceClient->fetchJwt($oauthAccessToken, $cookies);
    }

    /**
     * Authorization JWT for an OAuth access token.
     *
     * Calls GET /resources/jwt when the access token is opaque. Some IDP deployments already
     * issue JWT-shaped access tokens that work directly on /resources/userinfo; those are
     * returned as-is because /resources/jwt rejects authorization JWTs.
     */
    public function fetchJwtForAccessToken(string $oauthAccessToken, ?IdpHttpCookies $cookies = null): string
    {
        if (substr_count($oauthAccessToken, '.') === 2) {
            return $oauthAccessToken;
        }

        try {
            return $this->fetchJwt($oauthAccessToken, $cookies);
        } catch (ResourceException $exception) {
            if ($exception->errorCode() !== ErrorCode::ResourceUnauthorized) {
                throw $exception;
            }
        }

        return $oauthAccessToken;
    }

    public function checkAuthorization(Policy $policy, Requirement $requirement): AuthorizationCheck
    {
        return $this->authorizationEvaluator->evaluate($policy, $requirement);
    }

    /**
     * @param list<string> $orns ORN claim strings (JWT policy claim shape)
     */
    public function policyFromOrns(array $orns): Policy
    {
        return $this->ornParser->policyFromOrns($orns);
    }

    public function requirementFromOrn(string $orn): Requirement
    {
        return $this->ornParser->requirementFromOrn($orn);
    }

    public function refresh(TokenSet $tokens): TokenSet
    {
        $refreshToken = $tokens->refreshToken();
        if ($refreshToken === null || $refreshToken === '') {
            throw new TokenExchangeException(
                ErrorCode::TokenRefreshFailed,
                sprintf(
                    'Cannot refresh tokens: no refresh_token available. %s',
                    sprintf('See README %s for fix instructions.', ErrorCode::TokenRefreshFailed->readmeAnchor()),
                ),
            );
        }

        return $this->tokenClient->refresh($refreshToken);
    }

    public function clientIam(): ClientIamClient
    {
        ClientIamClient::requireSecret($this->environment);

        if ($this->clientIam === null) {
            $streams = $this->requests instanceof StreamFactoryInterface
                ? $this->requests
                : new \Nyholm\Psr7\Factory\Psr17Factory();
            $http = new Psr18ClientIamHttpClient(
                $this->environment,
                $this->http,
                $this->requests,
                $streams,
            );
            $this->clientIam = new ClientIamClient(
                $http,
                $this->environment->iamService(),
                $this->environment->iamServiceFormat(),
            );
        }

        return $this->clientIam;
    }

    /**
     * Bearer token to use on /resources/userinfo and /resources/validate for an OAuth access token.
     *
     * Opaque access tokens are elevated at /resources/jwt when the IDP supports it. Compact JWS
     * tokens are sent to userinfo directly — /resources/jwt rejects them as authorization JWTs.
     */
    private function resolveResourceBearer(string $oauthAccessToken, ?IdpHttpCookies $cookies = null): string
    {
        if (substr_count($oauthAccessToken, '.') === 2) {
            return $oauthAccessToken;
        }

        try {
            return $this->fetchJwt($oauthAccessToken, $cookies);
        } catch (ResourceException $exception) {
            if ($exception->errorCode() !== ErrorCode::ResourceUnauthorized) {
                throw $exception;
            }
        }

        return $oauthAccessToken;
    }

    private static function createDefaultProvider(
        IdpClientEnvironment $environment,
        ClientInterface $http,
    ): IdpProvider {
        if ($http instanceof \GuzzleHttp\ClientInterface) {
            return IdpProvider::fromEnvironment($environment, $http);
        }

        return IdpProvider::fromEnvironment($environment);
    }
}
