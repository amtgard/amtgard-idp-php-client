<?php

declare(strict_types=1);

namespace Amtgard\IdpClient\Tests;

use Amtgard\IdpClient\IdpClient;
use Amtgard\IdpClient\IdpClientFactory;
use Amtgard\IdpClient\InMemoryOAuthFlowStateStore;
use Amtgard\IdpClient\Tests\Support\MockPsr18Client;
use Amtgard\IdpClient\Tests\Support\Psr17HttpClient;
use Amtgard\IdpClient\Tests\Support\TestEnvironment;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(IdpClientFactory::class)]
final class IdpClientFactoryTest extends TestCase
{
    public function testFromEnvironmentBuildsClient(): void
    {
        $client = IdpClientFactory::fromEnvironment(
            TestEnvironment::create(),
            new InMemoryOAuthFlowStateStore(),
        );

        $this->assertInstanceOf(IdpClient::class, $client);
    }

    public function testFromEnvironmentReusesPsr17CapableHttpClient(): void
    {
        $http = new Psr17HttpClient(new MockPsr18Client());
        $client = IdpClientFactory::fromEnvironment(
            TestEnvironment::create(),
            new InMemoryOAuthFlowStateStore(),
            $http,
        );

        $this->assertInstanceOf(IdpClient::class, $client);
    }
}
