<?php

declare(strict_types=1);

namespace Amtgard\IdpClient\Tests\Support;

use Amtgard\IdpClient\Config\ArrayEnvironment;
use Amtgard\IdpClient\Config\IdpClientEnvironment;

final class TestEnvironment
{
    /**
     * @param array<string, mixed> $overrides
     */
    public static function create(array $overrides = []): ArrayEnvironment
    {
        return new ArrayEnvironment(
            idpBaseUrl: $overrides['idpBaseUrl'] ?? 'https://idp.test',
            clientId: $overrides['clientId'] ?? 'test-client',
            clientSecret: array_key_exists('clientSecret', $overrides) ? $overrides['clientSecret'] : 'secret',
            redirectUri: $overrides['redirectUri'] ?? 'https://app.test/oauth/callback',
            scopes: $overrides['scopes'] ?? ['profile', 'email'],
            httpUserAgent: $overrides['httpUserAgent'] ?? IdpClientEnvironment::DEFAULT_HTTP_USER_AGENT,
            iamService: $overrides['iamService'] ?? null,
            iamServiceFormat: $overrides['iamServiceFormat'] ?? null,
        );
    }
}
