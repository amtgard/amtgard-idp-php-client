<?php

declare(strict_types=1);

namespace Amtgard\IdpClient\Tests;

use Amtgard\IdpClient\Iam\AuthorizationCheck;
use Amtgard\IdpClient\Tests\Support\Fixtures;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(AuthorizationCheck::class)]
final class AuthorizationCheckTest extends TestCase
{
    public function testFromArrayTrue(): void
    {
        $data = json_decode(Fixtures::read('is_authorized_true.json'), true, 512, JSON_THROW_ON_ERROR);

        $check = AuthorizationCheck::fromArray($data);

        $this->assertTrue($check->isAuthorized);
    }

    public function testFromArrayDefaultsToFalse(): void
    {
        $check = AuthorizationCheck::fromArray([]);

        $this->assertFalse($check->isAuthorized);
    }
}
