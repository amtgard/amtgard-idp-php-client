<?php

declare(strict_types=1);

namespace Amtgard\IdpClient\Tests;

use Amtgard\IdpClient\Config\EnvIdpClientEnvironment;
use Amtgard\IdpClient\Exception\IdpConfigurationException;
use Amtgard\IdpClient\Config\IdpClientEnvironmentFactory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(IdpClientEnvironmentFactory::class)]
#[CoversClass(EnvIdpClientEnvironment::class)]
final class IdpClientEnvironmentFactoryTest extends TestCase
{
    public function testFromEnvVarsBuildsEnvironment(): void
    {
        $env = IdpClientEnvironmentFactory::fromEnvVars([
            'IDP_BASE_URL' => 'https://idp.test/',
            'IDP_CLIENT_ID' => 'app',
            'IDP_CLIENT_SECRET' => 'secret',
            'IDP_REDIRECT_URI' => 'https://app.test/callback',
            'IDP_HTTP_USER_AGENT' => 'TestApp/1.0',
        ]);

        $this->assertInstanceOf(EnvIdpClientEnvironment::class, $env);
        $this->assertSame('https://idp.test', $env->idpBaseUrl());
        $this->assertSame('app', $env->clientId());
        $this->assertSame('secret', $env->clientSecret());
        $this->assertSame('TestApp/1.0', $env->httpUserAgent());
    }

    public function testFromEnvVarsDefaultsHttpUserAgent(): void
    {
        $env = IdpClientEnvironmentFactory::fromEnvVars([
            'IDP_BASE_URL' => 'https://idp.test',
            'IDP_CLIENT_ID' => 'app',
            'IDP_REDIRECT_URI' => 'https://app.test/callback',
        ]);

        $this->assertSame('AmtgardIDP/1.0', $env->httpUserAgent());
    }

    public function testFromEnvVarsAllowsMissingSecretForPublicClient(): void
    {
        $env = IdpClientEnvironmentFactory::fromEnvVars([
            'IDP_BASE_URL' => 'https://idp.test',
            'IDP_CLIENT_ID' => 'public-app',
            'IDP_REDIRECT_URI' => 'https://app.test/callback',
        ]);

        $this->assertNull($env->clientSecret());
    }

    public function testFromEnvVarsThrowsWhenRequiredMissing(): void
    {
        try {
            IdpClientEnvironmentFactory::fromEnvVars([
                'IDP_BASE_URL' => 'https://idp.test',
            ]);
            $this->fail('Expected IdpConfigurationException');
        } catch (IdpConfigurationException $exception) {
            $this->assertContains('IDP_CLIENT_ID', $exception->missingVariables());
            $this->assertContains('IDP_REDIRECT_URI', $exception->missingVariables());
        }
    }
}
