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
        $this->resourceClient = new Psr18IdpHttpClient($environment, $http, $requests);
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
            $this->fetchUserProfile($result->tokens->accessToken(), $cookies),
            $result->returnTo,
            $cookies->toHeader(),
        );
    }

    /**
     * Load the full user profile for an OAuth access token.
     *
     * Prefers the documented elevation flow ({@see fetchJwt()} then userinfo).
     * If the IDP rejects elevation at `/resources/jwt`, falls back to calling `/resources/userinfo`
     * with the access token directly (supported on some deployed IDP builds).
     *
     * Pass {@see IdpHttpCookies} from {@see AuthenticatedSession::$idpCookies} (and persist
     * updates after the call) so later {@see validate()} can replay the IDP host session.
     */
    public function fetchUserProfile(string $oauthAccessToken, ?IdpHttpCookies $cookies = null): UserProfile
    {
        return $this->fetchUserProfileWithAuthorizationJwt(
            $this->resolveResourceBearer($oauthAccessToken, $cookies),
            $cookies,
        );
    }

    /**
     * Session heartbeat for an OAuth access token.
     *
     * Calls userinfo then validate with the same bearer token so the IDP Redis cache
     * (keyed on the userinfo Authorization header) matches the validate challenge JWT.
     *
     * Replay {@see IdpHttpCookies} from login / prior resource calls — validate requires an
     * active IDP browser session cookie, not only a bearer token.
     */
    public function validate(string $oauthAccessToken, ?IdpHttpCookies $cookies = null): ValidatedSession
    {
        $bearer = $this->resolveResourceBearer($oauthAccessToken, $cookies);
        $this->fetchUserProfileWithAuthorizationJwt($bearer, $cookies);

        return $this->validateWithAuthorizationJwt($bearer, $cookies);
    }

    /**
     * Authorization JWT for an OAuth access token.
     *
     * Calls GET /resources/jwt when the access token is opaque. Some IDP deployments already
     * issue JWT-shaped access tokens that work directly on /resources/userinfo; those are
     * returned as-is because /resources/jwt rejects authorization JWTs.
     */
    public function fetchJwt(string $oauthAccessToken, ?IdpHttpCookies $cookies = null): string
    {
        if (substr_count($oauthAccessToken, '.') === 2) {
            return $oauthAccessToken;
        }

        try {
            return $this->resourceClient->fetchJwt($oauthAccessToken, $cookies);
        } catch (ResourceException $exception) {
            if ($exception->errorCode() !== ErrorCode::ResourceUnauthorized) {
                throw $exception;
            }
        }

        return $oauthAccessToken;
    }

    /**
     * Low-level: GET /resources/userinfo with an authorization JWT (not an OAuth access token).
     *
     * Prefer {@see fetchUserProfile()} with the OAuth access token in application code.
     *
     * @param string $authorizationJwt RS256 authorization JWT from {@see fetchJwt()} or {@see UserProfile::$jwt}
     */
    public function fetchUserProfileWithAuthorizationJwt(
        string $authorizationJwt,
        ?IdpHttpCookies $cookies = null,
    ): UserProfile {
        return $this->resourceClient->fetchUserProfile($authorizationJwt, $cookies);
    }

    /**
     * Low-level: GET /resources/validate with an authorization JWT (not an OAuth access token).
     *
     * Prefer {@see validate()} with the OAuth access token. Requires IDP session cookies from
     * prior resource calls (see {@see IdpHttpCookies}).
     *
     * @param string $authorizationJwt RS256 authorization JWT from {@see fetchJwt()} or {@see UserProfile::$jwt}
     */
    public function validateWithAuthorizationJwt(
        string $authorizationJwt,
        ?IdpHttpCookies $cookies = null,
    ): ValidatedSession {
        return $this->resourceClient->validate($authorizationJwt, $cookies);
    }

    /**
     * Fetch user profile using the session's access token and IDP cookies.
     *
     * @return array{0: UserProfile, 1: AuthenticatedSession} profile and session with updated cookies/profile
     */
    public function fetchUserProfileForSession(AuthenticatedSession $session): array
    {
        $cookies = IdpHttpCookies::fromHeader($session->idpCookies);
        $profile = $this->fetchUserProfile($session->tokens->accessToken(), $cookies);

        return [
            $profile,
            $session->withProfile($profile)->withIdpCookies($cookies->toHeader()),
        ];
    }

    /**
     * Validate session heartbeat using the session's access token and IDP cookies.
     *
     * Updates the stored profile's id/email/jwt while preserving {@see UserProfile::$orkProfile}.
     *
     * @return array{0: ValidatedSession, 1: AuthenticatedSession}
     */
    public function validateForSession(AuthenticatedSession $session): array
    {
        $cookies = IdpHttpCookies::fromHeader($session->idpCookies);
        $validated = $this->validate($session->tokens->accessToken(), $cookies);
        $profile = new UserProfile(
            $validated->id,
            $validated->email,
            $validated->jwt,
            $session->profile->orkProfile,
        );

        return [
            $validated,
            $session->withProfile($profile)->withIdpCookies($cookies->toHeader()),
        ];
    }

    /**
     * Fetch (or reuse) an authorization JWT using the session's access token and IDP cookies.
     *
     * @return array{0: string, 1: AuthenticatedSession} JWT and session with updated cookies
     */
    public function fetchJwtForSession(AuthenticatedSession $session): array
    {
        $cookies = IdpHttpCookies::fromHeader($session->idpCookies);
        $jwt = $this->fetchJwt($session->tokens->accessToken(), $cookies);

        return [$jwt, $session->withIdpCookies($cookies->toHeader())];
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
            return $this->resourceClient->fetchJwt($oauthAccessToken, $cookies);
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
