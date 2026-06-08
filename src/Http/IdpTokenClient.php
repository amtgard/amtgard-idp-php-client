<?php

declare(strict_types=1);

namespace Amtgard\IdpClient\Http;

use Amtgard\IdpClient\Exception\ErrorCode;
use Amtgard\IdpClient\Exception\ErrorMapper;
use Amtgard\IdpClient\Exception\TokenExchangeException;
use Amtgard\IdpClient\IdpClientEnvironment;
use Amtgard\IdpClient\TokenSet;
use Psr\Http\Client\ClientExceptionInterface;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\StreamFactoryInterface;

final class IdpTokenClient
{
    private const TOKEN_PATH = '/oauth/token';

    public function __construct(
        private readonly IdpClientEnvironment $environment,
        private readonly ClientInterface $http,
        private readonly RequestFactoryInterface $requests,
        private readonly StreamFactoryInterface $streams,
    ) {}

    public function exchangeAuthorizationCode(string $code, string $codeVerifier): TokenSet
    {
        $params = [
            'grant_type' => 'authorization_code',
            'code' => $code,
            'code_verifier' => $codeVerifier,
            'client_id' => $this->environment->clientId(),
            'redirect_uri' => $this->environment->redirectUri(),
        ];

        $secret = $this->environment->clientSecret();
        if ($secret !== null && $secret !== '') {
            $params['client_secret'] = $secret;
        }

        return $this->requestToken($params, isRefresh: false);
    }

    public function refresh(string $refreshToken): TokenSet
    {
        $params = [
            'grant_type' => 'refresh_token',
            'refresh_token' => $refreshToken,
            'client_id' => $this->environment->clientId(),
        ];

        $secret = $this->environment->clientSecret();
        if ($secret !== null && $secret !== '') {
            $params['client_secret'] = $secret;
        }

        return $this->requestToken($params, isRefresh: true);
    }

    /**
     * @param array<string, string> $params
     */
    private function requestToken(array $params, bool $isRefresh): TokenSet
    {
        $url = $this->environment->idpBaseUrl() . self::TOKEN_PATH;
        $body = http_build_query($params);

        $request = $this->requests
            ->createRequest('POST', $url)
            ->withHeader('Content-Type', 'application/x-www-form-urlencoded')
            ->withHeader('Accept', 'application/json')
            ->withHeader('User-Agent', $this->environment->httpUserAgent())
            ->withBody($this->streams->createStream($body));

        try {
            $response = $this->http->sendRequest($request);
        } catch (ClientExceptionInterface $exception) {
            throw new TokenExchangeException(
                ErrorCode::HttpTransport,
                sprintf(
                    'HTTP transport error calling %s. %s',
                    self::TOKEN_PATH,
                    $exception->getMessage(),
                ),
                previous: $exception,
            );
        }

        $responseBody = (string) $response->getBody();
        $status = $response->getStatusCode();

        if ($wafCode = ErrorMapper::detectWafOrHtml($responseBody, $status)) {
            throw new TokenExchangeException(
                $wafCode,
                sprintf(
                    'Received HTML or WAF response from %s (HTTP %d). %s',
                    self::TOKEN_PATH,
                    $status,
                    sprintf('See README %s for fix instructions.', $wafCode->readmeAnchor()),
                ),
            );
        }

        $decoded = $this->decodeJson($responseBody, $status);

        if (isset($decoded['error'])) {
            throw ErrorMapper::mapTokenErrorPayload($decoded, $isRefresh);
        }

        if ($status < 200 || $status >= 300) {
            throw new TokenExchangeException(
                ErrorCode::TokenExchangeFailed,
                sprintf(
                    'Unexpected HTTP %d from %s. %s',
                    $status,
                    self::TOKEN_PATH,
                    sprintf('See README %s for fix instructions.', ErrorCode::TokenExchangeFailed->readmeAnchor()),
                ),
            );
        }

        return $this->mapTokenSet($decoded);
    }

    /**
     * @return array<string, mixed>
     */
    private function decodeJson(string $body, int $status): array
    {
        if ($body === '') {
            throw new TokenExchangeException(
                ErrorCode::MalformedJson,
                sprintf(
                    'Empty response body from %s (HTTP %d). %s',
                    self::TOKEN_PATH,
                    $status,
                    sprintf('See README %s for fix instructions.', ErrorCode::MalformedJson->readmeAnchor()),
                ),
            );
        }

        try {
            $decoded = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $exception) {
            throw new TokenExchangeException(
                ErrorCode::MalformedJson,
                sprintf(
                    'Malformed JSON from %s. %s',
                    self::TOKEN_PATH,
                    sprintf('See README %s for fix instructions.', ErrorCode::MalformedJson->readmeAnchor()),
                ),
                previous: $exception,
            );
        }

        if (!is_array($decoded)) {
            throw new TokenExchangeException(
                ErrorCode::MalformedJson,
                sprintf('Expected JSON object from %s.', self::TOKEN_PATH),
            );
        }

        return $decoded;
    }

    /**
     * @param array<string, mixed> $data
     */
    private function mapTokenSet(array $data): TokenSet
    {
        $accessToken = $data['access_token'] ?? null;
        if (!is_string($accessToken) || $accessToken === '') {
            throw new TokenExchangeException(
                ErrorCode::MalformedJson,
                sprintf('Token response from %s is missing access_token.', self::TOKEN_PATH),
            );
        }

        $expiresAt = null;
        if (isset($data['expires_in']) && is_numeric($data['expires_in'])) {
            $expiresAt = (new \DateTimeImmutable())->modify('+' . (int) $data['expires_in'] . ' seconds');
        }

        $refreshToken = $data['refresh_token'] ?? null;

        return new TokenSet(
            $accessToken,
            is_string($refreshToken) && $refreshToken !== '' ? $refreshToken : null,
            $expiresAt,
        );
    }
}
