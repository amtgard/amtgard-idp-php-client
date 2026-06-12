<?php

declare(strict_types=1);

namespace Amtgard\IdpClient\Tests;

use Amtgard\IdpClient\Client\IdpClient;
use Amtgard\IdpClient\Config\IdpClientFactory;
use PHPUnit\Framework\TestCase;

final class IdpClientFactoryEnvTest extends TestCase
{
    public function testFromEnvVarsBuildsClient(): void
    {
        $client = IdpClientFactory::fromEnvVars([
            'IDP_BASE_URL' => 'https://idp.test',
            'IDP_CLIENT_ID' => 'app',
            'IDP_REDIRECT_URI' => 'https://app.test/callback',
        ]);

        $this->assertInstanceOf(IdpClient::class, $client);
    }
}
