<?php

declare(strict_types=1);

namespace Amtgard\IdpSlimExample\Controllers;

use Amtgard\IdpClient\Client\IdpClient;
use Amtgard\IdpClient\ClientIam\ClientIamClient;
use Amtgard\IdpClient\Exception\ClientIamException;
use Amtgard\IdpClient\Exception\IdpClientException;
use Amtgard\IdpSlimExample\Config\ClientIamExampleDefaults;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Demonstrates Client IAM (Section 8) when IDP_CLIENT_SECRET and IDP_IAM_SERVICE are configured.
 */
final class ClientIamController
{
    public function __construct(
        private readonly IdpClient $idpClient,
        private readonly ?ClientIamClient $clientIam,
    ) {}

    public function serviceFormat(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        return $this->execute(
            $response,
            fn () => $this->requireClientIam()->getServiceFormat(),
            static function ($format) {
                $composeDefaults = ClientIamExampleDefaults::fromServiceFormat($format);

                return [
                    'iam_service' => $format->iamService ?? $composeDefaults['iam_service'],
                    'service_format' => $format->serviceFormat,
                    'is_default' => $format->isDefault,
                    'compose_defaults' => [
                        'segments' => $composeDefaults['segments'],
                        'resource' => $composeDefaults['resource'],
                    ],
                ];
            },
        );
    }

    public function composeClaim(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $body = $this->parseJsonBody($request);
        $clientIam = $this->requireClientIam();
        $derived = ClientIamExampleDefaults::fromServiceFormat($clientIam->getServiceFormat());

        $useDefaultSegments = !array_key_exists('segments', $body)
            || (is_array($body['segments']) && $body['segments'] === []);
        $useDefaultResource = !array_key_exists('resource', $body)
            || !is_string($body['resource'])
            || $body['resource'] === '';

        $segments = $useDefaultSegments ? $derived['segments'] : $body['segments'];
        if (!is_array($segments)) {
            return $this->json($response, ['error' => 'segments must be a JSON object'], 400);
        }

        $resource = $useDefaultResource ? $derived['resource'] : $body['resource'];

        return $this->execute(
            $response,
            fn () => $clientIam->composeClaim($segments, $resource),
            static fn ($claim) => ['orn' => $claim->buildOrn()],
        );
    }

    private function requireClientIam(): ClientIamClient
    {
        if ($this->clientIam === null) {
            throw new ClientIamException(
                \Amtgard\IdpClient\Exception\ErrorCode::ClientIamMissingSecret,
                'Client IAM is not configured for this example (set IDP_CLIENT_SECRET and IDP_IAM_SERVICE).',
            );
        }

        return $this->clientIam;
    }

    /**
     * @param callable(): mixed $action
     * @param callable(mixed): array<string, mixed> $map
     */
    private function execute(ResponseInterface $response, callable $action, callable $map): ResponseInterface
    {
        try {
            return $this->json($response, $map($action()));
        } catch (IdpClientException $exception) {
            return $this->json($response, [
                'error_code' => $exception->errorCode()->value,
                'message' => $exception->getMessage(),
            ], 400);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function parseJsonBody(ServerRequestInterface $request): array
    {
        $body = $request->getParsedBody();
        if (is_array($body)) {
            return $body;
        }

        $raw = (string) $request->getBody();
        if ($raw === '') {
            return [];
        }

        try {
            $decoded = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return [];
        }

        return is_array($decoded) ? $decoded : [];
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function json(ResponseInterface $response, array $payload, int $status = 200): ResponseInterface
    {
        $response->getBody()->write(json_encode($payload, JSON_THROW_ON_ERROR));

        return $response
            ->withHeader('Content-Type', 'application/json')
            ->withStatus($status);
    }
}
