<?php

declare(strict_types=1);

namespace Amtgard\IdpClient;

use Amtgard\IdpClient\Exception\ErrorCode;
use Amtgard\IdpClient\Exception\InvalidOAuthStateException;
use Amtgard\IdpClient\Exception\TokenExchangeException;
use Amtgard\IdpClient\Http\IdpTokenClient;
use Amtgard\IdpClient\Http\Psr18IdpHttpClient;
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

    public function __construct(
        private readonly IdpClientEnvironment $environment,
        private readonly OAuthFlowStateStore $flowState,
        ClientInterface $http,
        RequestFactoryInterface $requests,
        private readonly ResponseFactoryInterface $responses,
        ?IdpProvider $provider = null,
    ) {
        $this->provider = $provider ?? self::createDefaultProvider($environment, $http);
        $streams = $requests instanceof StreamFactoryInterface
            ? $requests
            : new \Nyholm\Psr7\Factory\Psr17Factory();
        $this->tokenClient = new IdpTokenClient($environment, $http, $requests, $streams);
        $this->resourceClient = new Psr18IdpHttpClient($environment, $http, $requests, $streams);
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

        return new AuthenticatedSession(
            $result->tokens,
            $this->fetchUserProfile($result->tokens->accessToken()),
            $result->returnTo,
        );
    }

    public function fetchUserProfile(string $accessToken): UserProfile
    {
        return $this->resourceClient->fetchUserProfile($accessToken);
    }

    public function validate(string $accessToken): ValidatedSession
    {
        return $this->resourceClient->validate($accessToken);
    }

    public function fetchJwt(string $accessToken): string
    {
        return $this->resourceClient->fetchJwt($accessToken);
    }

    /**
     * @param list<mixed> $policy IAM policy ORN JSON array
     */
    public function checkAuthorization(array $policy, string $requirement): AuthorizationCheck
    {
        return $this->resourceClient->checkAuthorization($policy, $requirement);
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

    private static function createDefaultProvider(
        IdpClientEnvironment $environment,
        ClientInterface $http,
    ): IdpProvider {
        // League's provider only accepts Guzzle; token exchange uses the PSR-18 client directly.
        if ($http instanceof \GuzzleHttp\ClientInterface) {
            return IdpProvider::fromEnvironment($environment, $http);
        }

        return IdpProvider::fromEnvironment($environment);
    }
}
