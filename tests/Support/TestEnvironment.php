<?php

declare(strict_types=1);

namespace Amtgard\IdpClient\Tests\Support;

use Amtgard\IdpClient\ArrayEnvironment;

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
            clientSecret: $overrides['clientSecret'] ?? 'secret',
            redirectUri: $overrides['redirectUri'] ?? 'https://app.test/oauth/callback',
            scopes: $overrides['scopes'] ?? ['profile', 'email'],
            httpUserAgent: $overrides['httpUserAgent'] ?? 'test-agent/1.0',
        );
    }
}
