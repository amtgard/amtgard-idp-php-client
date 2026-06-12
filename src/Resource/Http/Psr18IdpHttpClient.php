<?php

declare(strict_types=1);

namespace Amtgard\IdpClient\Resource\Http;

use Amtgard\IdpClient\Exception\ErrorCode;
use Amtgard\IdpClient\Exception\ErrorMapper;
use Amtgard\IdpClient\Exception\ResourceException;
use Amtgard\IdpClient\Config\IdpClientEnvironment;
use Amtgard\IdpClient\Resource\UserProfile;
use Amtgard\IdpClient\Resource\ValidatedSession;
use Psr\Http\Client\ClientExceptionInterface;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\StreamFactoryInterface;

final class Psr18IdpHttpClient
{
    public function __construct(
        private readonly IdpClientEnvironment $environment,
        private readonly ClientInterface $http,
        private readonly RequestFactoryInterface $requests,
        private readonly StreamFactoryInterface $streams,
    ) {}

    public function fetchUserProfile(string $accessToken): UserProfile
    {
        $response = $this->getResource('/resources/userinfo', $accessToken);
        $data = $this->decodeJson($response['body'], $response['status'], '/resources/userinfo');

        return UserProfile::fromArray($data);
    }

    public function validate(string $accessToken): ValidatedSession
    {
        $response = $this->getResource('/resources/validate', $accessToken);
        $data = $this->decodeJson($response['body'], $response['status'], '/resources/validate');

        return ValidatedSession::fromArray($data);
    }

    public function fetchJwt(string $accessToken): string
    {
        $response = $this->getResource('/resources/jwt', $accessToken);
        $data = $this->decodeJson($response['body'], $response['status'], '/resources/jwt');

        $jwt = $data['jwt'] ?? null;
        if (!is_string($jwt) || $jwt === '') {
            throw new ResourceException(
                ErrorCode::MalformedJson,
                'Expected non-empty jwt string from /resources/jwt.',
            );
        }

        return $jwt;
    }

    /**
     * @return array{status: int, body: string}
     */
    private function getResource(string $path, string $accessToken): array
    {
        $url = $this->environment->idpBaseUrl() . $path;

        $request = $this->requests
            ->createRequest('GET', $url)
            ->withHeader('Authorization', 'Bearer ' . $accessToken)
            ->withHeader('Accept', 'application/json')
            ->withHeader('User-Agent', $this->environment->httpUserAgent());

        return $this->sendAndValidate($path, $request, unauthorized: true);
    }

    /**
     * @return array{status: int, body: string}
     */
    private function sendAndValidate(string $path, \Psr\Http\Message\RequestInterface $request, bool $unauthorized): array
    {
        try {
            $response = $this->http->sendRequest($request);
        } catch (ClientExceptionInterface $exception) {
            throw new ResourceException(
                ErrorCode::HttpTransport,
                sprintf(
                    'HTTP transport error calling %s. %s',
                    $path,
                    $exception->getMessage(),
                ),
                previous: $exception,
            );
        }

        $body = (string) $response->getBody();
        $status = $response->getStatusCode();

        if ($wafCode = ErrorMapper::detectWafOrHtml($body, $status)) {
            throw new ResourceException(
                $wafCode,
                sprintf(
                    'Received HTML or WAF response from %s (HTTP %d). %s',
                    $path,
                    $status,
                    sprintf('See README %s for fix instructions.', $wafCode->readmeAnchor()),
                ),
            );
        }

        if ($unauthorized && $status === 401) {
            throw new ResourceException(
                ErrorCode::ResourceUnauthorized,
                sprintf(
                    'Access token rejected by %s (HTTP 401). %s',
                    $path,
                    sprintf('See README %s for fix instructions.', ErrorCode::ResourceUnauthorized->readmeAnchor()),
                ),
            );
        }

        if ($status === 422) {
            throw new ResourceException(
                ErrorCode::ResourcePolicyError,
                sprintf(
                    'IDP user policy error on %s (HTTP 422). %s',
                    $path,
                    sprintf('See README %s for fix instructions.', ErrorCode::ResourcePolicyError->readmeAnchor()),
                ),
            );
        }

        if ($status < 200 || $status >= 300) {
            throw new ResourceException(
                ErrorCode::ResourceUnexpectedStatus,
                sprintf(
                    'Unexpected HTTP %d from %s. %s',
                    $status,
                    $path,
                    sprintf('See README %s for fix instructions.', ErrorCode::ResourceUnexpectedStatus->readmeAnchor()),
                ),
            );
        }

        return ['status' => $status, 'body' => $body];
    }

    /**
     * @return array<string, mixed>
     */
    private function decodeJson(string $body, int $status, string $path): array
    {
        if ($body === '') {
            return [];
        }

        try {
            $decoded = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $exception) {
            throw new ResourceException(
                ErrorCode::MalformedJson,
                sprintf(
                    'Malformed JSON from %s. %s',
                    $path,
                    sprintf('See README %s for fix instructions.', ErrorCode::MalformedJson->readmeAnchor()),
                ),
                previous: $exception,
            );
        }

        if (!is_array($decoded)) {
            throw new ResourceException(
                ErrorCode::MalformedJson,
                sprintf('Expected JSON object from %s.', $path),
            );
        }

        return $decoded;
    }
}
