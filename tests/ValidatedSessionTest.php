<?php

declare(strict_types=1);

namespace Amtgard\IdpClient\Tests;

use Amtgard\IdpClient\Tests\Support\Fixtures;
use Amtgard\IdpClient\Resource\ValidatedSession;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(ValidatedSession::class)]
final class ValidatedSessionTest extends TestCase
{
    public function testFromArray(): void
    {
        $data = json_decode(Fixtures::read('validate.json'), true, 512, JSON_THROW_ON_ERROR);

        $session = ValidatedSession::fromArray($data);

        $this->assertSame(42, $session->id);
        $this->assertSame('player@amtgard.com', $session->email);
        $this->assertSame('eyJhbGciOiJIUzI1NiJ9.test', $session->jwt);
    }
}
