<?php

declare(strict_types=1);

namespace Amtgard\IdpClient\Tests;

use Amtgard\IdpClient\Resource\AuthenticatedSession;
use Amtgard\IdpClient\Session\SessionAuthStore;
use Amtgard\IdpClient\Tests\Support\Fixtures;
use Amtgard\IdpClient\OAuth\TokenSet;
use Amtgard\IdpClient\Resource\UserProfile;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(SessionAuthStore::class)]
#[CoversClass(AuthenticatedSession::class)]
final class SessionAuthStoreTest extends TestCase
{
    protected function setUp(): void
    {
        @session_start();
        $_SESSION = [];
    }

    public function testStoreGetAndClear(): void
    {
        $store = new SessionAuthStore();
        $profile = UserProfile::fromArray(
            json_decode(Fixtures::read('userinfo_with_ork.json'), true, 512, JSON_THROW_ON_ERROR),
        );
        $session = new AuthenticatedSession(
            new TokenSet('access', 'refresh', new \DateTimeImmutable('+1 hour')),
            $profile,
            '/dashboard',
        );

        $store->store($session);

        $this->assertTrue($store->isAuthenticated());
        $loaded = $store->get();
        $this->assertNotNull($loaded);
        $this->assertSame('access', $loaded->tokens->accessToken());
        $this->assertSame('/dashboard', $loaded->returnTo);
        $this->assertNotNull($loaded->profile->orkProfile);

        $store->clear();
        $this->assertFalse($store->isAuthenticated());
    }
}
