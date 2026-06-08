<?php

declare(strict_types=1);

namespace Amtgard\IdpClient;

use GuzzleHttp\Client as GuzzleClient;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\StreamFactoryInterface;

final class IdpClientFactory
{
    /**
     * On-rails bootstrap: read standard IDP_* env vars and wire a session-backed client.
     *
     * @param array<string, mixed>|null $env
     */
    public static function fromEnvVars(
        ?array $env = null,
        ?OAuthFlowStateStore $flowState = null,
        ?ClientInterface $http = null,
    ): IdpClient {
        return self::fromEnvironment(
            IdpClientEnvironmentFactory::fromEnvVars($env),
            $flowState ?? new SessionOAuthFlowStateStore(),
            $http,
        );
    }

    public static function fromEnvironment(
        IdpClientEnvironment $environment,
        OAuthFlowStateStore $flowState,
        ?ClientInterface $http = null,
    ): IdpClient {
        if (!class_exists(GuzzleClient::class)) {
            throw new \RuntimeException(
                'Install guzzlehttp/guzzle to use IdpClientFactory, or construct IdpClient manually with a PSR-18 client.',
            );
        }

        $guzzle = $http ?? new GuzzleClient([
            'headers' => [
                'User-Agent' => $environment->httpUserAgent(),
                'Accept' => 'application/json',
            ],
        ]);

        $psr17 = self::resolvePsr17Factory($guzzle);

        return new IdpClient(
            $environment,
            $flowState,
            $guzzle,
            $psr17,
            $psr17,
        );
    }

    private static function resolvePsr17Factory(ClientInterface $client): RequestFactoryInterface&ResponseFactoryInterface&StreamFactoryInterface
    {
        if (
            $client instanceof RequestFactoryInterface
            && $client instanceof ResponseFactoryInterface
            && $client instanceof StreamFactoryInterface
        ) {
            return $client;
        }

        return new \Nyholm\Psr7\Factory\Psr17Factory();
    }
}
