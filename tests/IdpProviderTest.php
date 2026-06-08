<?php

declare(strict_types=1);

namespace Amtgard\IdpClient\Tests;

use Amtgard\IdpClient\IdpProvider;
use Amtgard\IdpClient\Tests\Support\TestEnvironment;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(IdpProvider::class)]
final class IdpProviderTest extends TestCase
{
    public function testFromEnvironmentBuildsCorrectUrls(): void
    {
        $env = TestEnvironment::create();
        $provider = IdpProvider::fromEnvironment($env);

        $url = $provider->getAuthorizationUrl(['state' => 'fixed-state']);

        $this->assertStringContainsString('client_id=test-client', $url);
        $this->assertStringContainsString('redirect_uri=', $url);
        $this->assertStringContainsString('scope=profile%20email', $url);
        $this->assertStringNotContainsString('scope=profile,email', $url);
    }
}
