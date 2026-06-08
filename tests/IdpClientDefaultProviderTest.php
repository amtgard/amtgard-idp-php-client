<?php

declare(strict_types=1);

namespace Amtgard\IdpClient\Tests;

use Amtgard\IdpClient\IdpClient;
use Amtgard\IdpClient\InMemoryOAuthFlowStateStore;
use Amtgard\IdpClient\Tests\Support\TestEnvironment;
use Nyholm\Psr7\Factory\Psr17Factory;
use PHPUnit\Framework\TestCase;

final class IdpClientDefaultProviderTest extends TestCase
{
    public function testConstructsWithDefaultProviderFromEnvironment(): void
    {
        $psr17 = new Psr17Factory();
        $client = new IdpClient(
            TestEnvironment::create(),
            new InMemoryOAuthFlowStateStore(),
            new \Amtgard\IdpClient\Tests\Support\MockPsr18Client(),
            $psr17,
            $psr17,
        );

        $response = $client->beginAuthorization();

        $this->assertSame(302, $response->getStatusCode());
    }
}
