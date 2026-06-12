<?php

declare(strict_types=1);

namespace Amtgard\IdpClient\Tests;

use Amtgard\IdpClient\Config\ArrayEnvironment;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(ArrayEnvironment::class)]
final class ArrayEnvironmentTest extends TestCase
{
    public function testDefaults(): void
    {
        $env = new ArrayEnvironment(
            'https://idp.test',
            'client',
            null,
            'https://app.test/callback',
        );

        $this->assertSame('https://idp.test', $env->idpBaseUrl());
        $this->assertSame('client', $env->clientId());
        $this->assertSame('https://app.test/callback', $env->redirectUri());
        $this->assertSame(['profile', 'email'], $env->scopes());
        $this->assertSame('AmtgardIDP/1.0', $env->httpUserAgent());
        $this->assertNull($env->clientSecret());
    }

    public function testCustomScopesAndUserAgent(): void
    {
        $env = new ArrayEnvironment(
            'https://idp.test',
            'client',
            'sekrit',
            'https://app.test/callback',
            scopes: ['profile'],
            httpUserAgent: 'Custom/2.0',
        );

        $this->assertSame('sekrit', $env->clientSecret());
        $this->assertSame(['profile'], $env->scopes());
        $this->assertSame('Custom/2.0', $env->httpUserAgent());
    }
}
