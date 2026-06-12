<?php

declare(strict_types=1);

namespace Amtgard\IdpClient\Tests;

use Amtgard\IdpClient\OAuth\TokenSet;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(TokenSet::class)]
final class TokenSetTest extends TestCase
{
    public function testIsExpiredWhenExpiresAtInPast(): void
    {
        $tokens = new TokenSet('access', 'refresh', new \DateTimeImmutable('-1 hour'));

        $this->assertTrue($tokens->isExpired());
    }

    public function testIsNotExpiredWhenExpiresAtInFuture(): void
    {
        $tokens = new TokenSet('access', 'refresh', new \DateTimeImmutable('+1 hour'));

        $this->assertFalse($tokens->isExpired());
    }

    public function testIsNotExpiredWhenNoExpiry(): void
    {
        $tokens = new TokenSet('access');

        $this->assertFalse($tokens->isExpired());
    }

    public function testAccessors(): void
    {
        $expires = new \DateTimeImmutable('+1 hour');
        $tokens = new TokenSet('access-token', 'refresh-token', $expires);

        $this->assertSame('access-token', $tokens->accessToken());
        $this->assertSame('refresh-token', $tokens->refreshToken());
        $this->assertSame($expires, $tokens->expiresAt());
    }
}
