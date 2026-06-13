<?php

declare(strict_types=1);

namespace Amtgard\IdpClient\ClientIam\Http;

use Amtgard\IdpClient\ClientIam\Model\PolicyClaimList;
use Amtgard\IdpClient\ClientIam\Model\ServiceFormat;
use Amtgard\IdpClient\ClientIam\Model\UserMetadata;
use Amtgard\IdpClient\Config\IdpClientEnvironment;
use Amtgard\IdpClient\Exception\ClientIamException;
use Amtgard\IdpClient\Exception\ErrorCode;
use Amtgard\IdpClient\Exception\ErrorMapper;
use Psr\Http\Client\ClientExceptionInterface;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\StreamFactoryInterface;

final class Psr18ClientIamHttpClient
{
    private const BASE_PATH = '/resources/client';

    public function __construct(
        private readonly IdpClientEnvironment $environment,
        private readonly ClientInterface $http,
        private readonly RequestFactoryInterface $requests,
        private readonly StreamFactoryInterface $streams,
    ) {}

    public function getServiceFormat(): ServiceFormat
    {
        $response = $this->send(
            'GET',
            self::BASE_PATH . '/service-format',
            expectBody: true,
        );

        return ServiceFormat::fromArray($response);
    }

    /**
     * @param list<string> $serviceFormat
     */
    public function createServiceFormat(array $serviceFormat): void
    {
        $this->send(
            'POST',
            self::BASE_PATH . '/service-format',
            body: ['service_format' => $serviceFormat],
            allowNoContent: true,
        );
    }

    /**
     * @param list<string> $serviceFormat
     */
    public function replaceServiceFormat(array $serviceFormat): void
    {
        $this->send(
            'PUT',
            self::BASE_PATH . '/service-format',
            body: ['service_format' => $serviceFormat],
            allowNoContent: true,
        );
    }

    public function addPolicyClaim(string $idpUserId, string $provisos, string $resource): void
    {
        $this->send(
            'POST',
            self::BASE_PATH . '/policy-claims',
            body: [
                'idp_user_id' => $idpUserId,
                'provisos' => $provisos,
                'resource' => $resource,
            ],
            allowNoContent: true,
        );
    }

    public function deletePolicyClaim(string $idpUserId, string $provisos, string $resource): void
    {
        $this->send(
            'DELETE',
            self::BASE_PATH . '/policy-claims',
            body: [
                'idp_user_id' => $idpUserId,
                'provisos' => $provisos,
                'resource' => $resource,
            ],
            allowNoContent: true,
        );
    }

    public function listPolicyClaims(string $idpUserId): PolicyClaimList
    {
        $response = $this->send(
            'GET',
            self::BASE_PATH . '/policy-claims/' . rawurlencode($idpUserId),
            expectBody: true,
        );

        return PolicyClaimList::fromArray($response);
    }

    /**
     * @param array<string, mixed>|string $metadata
     */
    public function putUserMetadata(
        string $idpUserId,
        int $loginId,
        array|string $metadata,
        string $encoding,
    ): void {
        $this->send(
            'PUT',
            self::BASE_PATH . '/user-metadata',
            body: [
                'idp_user_id' => $idpUserId,
                'login_id' => $loginId,
                'metadata' => $metadata,
                'encoding' => $encoding,
            ],
            allowNoContent: true,
        );
    }

    public function getUserMetadata(string $idpUserId, int $loginId): UserMetadata
    {
        $response = $this->send(
            'GET',
            self::BASE_PATH . '/user-metadata/' . rawurlencode($idpUserId)
                . '?login_id=' . rawurlencode((string) $loginId),
            expectBody: true,
        );

        return UserMetadata::fromArray($response);
    }

    public function deleteUserMetadata(string $idpUserId, int $loginId): void
    {
        $this->send(
            'DELETE',
            self::BASE_PATH . '/user-metadata/' . rawurlencode($idpUserId)
                . '?login_id=' . rawurlencode((string) $loginId),
            allowNoContent: true,
        );
    }

    /**
     * @param array<string, mixed>|null $body
     *
     * @return array<string, mixed>
     */
    private function send(
        string $method,
        string $path,
        ?array $body = null,
        bool $expectBody = false,
        bool $allowNoContent = false,
    ): array {
        $url = $this->environment->idpBaseUrl() . $path;
        $request = $this->requests
            ->createRequest($method, $url)
            ->withHeader('Accept', 'application/json')
            ->withHeader('Authorization', $this->basicAuthorization())
            ->withHeader('User-Agent', $this->environment->httpUserAgent());

        if ($body !== null) {
            $json = json_encode($body, JSON_THROW_ON_ERROR);
            $request = $request
                ->withHeader('Content-Type', 'application/json')
                ->withBody($this->streams->createStream($json));
        }

        return $this->sendAndValidate($path, $request, $expectBody, $allowNoContent);
    }

    private function basicAuthorization(): string
    {
        $secret = $this->environment->clientSecret();
        if ($secret === null || $secret === '') {
            throw new ClientIamException(
                ErrorCode::ClientIamMissingSecret,
                sprintf(
                    'Client IAM requires a confidential client secret. %s',
                    sprintf('See README %s for fix instructions.', ErrorCode::ClientIamMissingSecret->readmeAnchor()),
                ),
            );
        }

        return 'Basic ' . base64_encode($this->environment->clientId() . ':' . $secret);
    }

    /**
     * @return array<string, mixed>
     */
    private function sendAndValidate(
        string $path,
        RequestInterface $request,
        bool $expectBody,
        bool $allowNoContent,
    ): array {
        try {
            $response = $this->http->sendRequest($request);
        } catch (ClientExceptionInterface $exception) {
            throw new ClientIamException(
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
            throw new ClientIamException(
                $wafCode,
                sprintf(
                    'Received HTML or WAF response from %s (HTTP %d). %s',
                    $path,
                    $status,
                    sprintf('See README %s for fix instructions.', $wafCode->readmeAnchor()),
                ),
            );
        }

        if ($status === 401) {
            throw new ClientIamException(
                ErrorCode::ClientIamUnauthorized,
                sprintf(
                    'Client IAM credentials rejected by %s (HTTP 401). %s',
                    $path,
                    sprintf('See README %s for fix instructions.', ErrorCode::ClientIamUnauthorized->readmeAnchor()),
                ),
                idpError: $this->extractErrorMessage($body),
            );
        }

        if ($status === 400) {
            throw new ClientIamException(
                ErrorCode::ClientIamValidation,
                sprintf(
                    'Client IAM validation failed on %s (HTTP 400): %s. %s',
                    $path,
                    $this->extractErrorMessage($body) ?? 'unknown error',
                    sprintf('See README %s for fix instructions.', ErrorCode::ClientIamValidation->readmeAnchor()),
                ),
                idpError: $this->extractErrorMessage($body),
            );
        }

        if ($status === 404) {
            throw new ClientIamException(
                ErrorCode::ClientIamNotFound,
                sprintf(
                    'Client IAM resource not found on %s (HTTP 404): %s. %s',
                    $path,
                    $this->extractErrorMessage($body) ?? 'unknown error',
                    sprintf('See README %s for fix instructions.', ErrorCode::ClientIamNotFound->readmeAnchor()),
                ),
                idpError: $this->extractErrorMessage($body),
            );
        }

        if ($status === 409) {
            throw new ClientIamException(
                ErrorCode::ClientIamConflict,
                sprintf(
                    'Client IAM conflict on %s (HTTP 409): %s. %s',
                    $path,
                    $this->extractErrorMessage($body) ?? 'unknown error',
                    sprintf('See README %s for fix instructions.', ErrorCode::ClientIamConflict->readmeAnchor()),
                ),
                idpError: $this->extractErrorMessage($body),
            );
        }

        if ($allowNoContent && ($status === 204 || ($status >= 200 && $status < 300 && $body === ''))) {
            return [];
        }

        if ($status < 200 || $status >= 300) {
            throw new ClientIamException(
                ErrorCode::ClientIamUnexpectedStatus,
                sprintf(
                    'Unexpected HTTP %d from %s. %s',
                    $status,
                    $path,
                    sprintf('See README %s for fix instructions.', ErrorCode::ClientIamUnexpectedStatus->readmeAnchor()),
                ),
            );
        }

        if (!$expectBody) {
            return [];
        }

        return $this->decodeJson($body, $path);
    }

    /**
     * @return array<string, mixed>
     */
    private function decodeJson(string $body, string $path): array
    {
        if ($body === '') {
            return [];
        }

        try {
            $decoded = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $exception) {
            throw new ClientIamException(
                ErrorCode::ClientIamMalformedJson,
                sprintf(
                    'Malformed JSON from %s. %s',
                    $path,
                    sprintf('See README %s for fix instructions.', ErrorCode::ClientIamMalformedJson->readmeAnchor()),
                ),
                previous: $exception,
            );
        }

        if (!is_array($decoded)) {
            throw new ClientIamException(
                ErrorCode::ClientIamMalformedJson,
                sprintf('Expected JSON object from %s.', $path),
            );
        }

        return $decoded;
    }

    private function extractErrorMessage(string $body): ?string
    {
        if ($body === '') {
            return null;
        }

        try {
            $decoded = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return null;
        }

        if (!is_array($decoded)) {
            return null;
        }

        $error = $decoded['error'] ?? null;

        return is_string($error) && $error !== '' ? $error : null;
    }
}
