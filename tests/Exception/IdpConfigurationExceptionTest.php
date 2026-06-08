<?php

declare(strict_types=1);

namespace Amtgard\IdpClient\Tests\Exception;

use Amtgard\IdpClient\Exception\IdpConfigurationException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(IdpConfigurationException::class)]
final class IdpConfigurationExceptionTest extends TestCase
{
    public function testReportsMissingVariables(): void
    {
        $exception = new IdpConfigurationException(['IDP_CLIENT_ID', 'IDP_REDIRECT_URI']);

        $this->assertStringContainsString('IDP_CLIENT_ID', $exception->getMessage());
        $this->assertSame(['IDP_CLIENT_ID', 'IDP_REDIRECT_URI'], $exception->missingVariables());
    }
}
