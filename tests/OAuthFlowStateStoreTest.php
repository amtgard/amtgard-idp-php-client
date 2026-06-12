<?php

declare(strict_types=1);

namespace Amtgard\IdpClient\Tests;

use Amtgard\IdpClient\OAuth\InMemoryOAuthFlowStateStore;
use Amtgard\IdpClient\OAuth\OAuthFlowState;
use Amtgard\IdpClient\OAuth\SessionOAuthFlowStateStore;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(InMemoryOAuthFlowStateStore::class)]
#[CoversClass(SessionOAuthFlowStateStore::class)]
#[CoversClass(OAuthFlowState::class)]
final class OAuthFlowStateStoreTest extends TestCase
{
    public function testInMemoryStoreFlashSemantics(): void
    {
        $store = new InMemoryOAuthFlowStateStore();
        $state = new OAuthFlowState('state-1', 'verifier-1', '/home');

        $store->put($state);

        $this->assertSame($state, $store->pull());
        $this->assertNull($store->pull());
    }

    public function testSessionStorePersistsAndClears(): void
    {
        @session_start();
        $_SESSION = [];

        $store = new SessionOAuthFlowStateStore('test_session_key');
        $state = new OAuthFlowState('state-2', 'verifier-2');

        $store->put($state);

        $pulled = $store->pull();
        $this->assertNotNull($pulled);
        $this->assertSame($state->state, $pulled->state);
        $this->assertSame($state->codeVerifier, $pulled->codeVerifier);
        $this->assertArrayNotHasKey('test_session_key', $_SESSION);
    }

    public function testSessionStoreRoundTripsReturnTo(): void
    {
        @session_start();
        $_SESSION = [];

        $store = new SessionOAuthFlowStateStore('return_to_key');
        $store->put(new OAuthFlowState('s', 'v', '/after-login'));

        $pulled = $store->pull();
        $this->assertNotNull($pulled);
        $this->assertSame('/after-login', $pulled->returnTo);
    }

    public function testSessionStoreReturnsNullForInvalidPayload(): void
    {
        @session_start();
        $_SESSION = ['bad_key' => 'not-an-array'];

        $store = new SessionOAuthFlowStateStore('bad_key');

        $this->assertNull($store->pull());
    }

    public function testSessionStoreReturnsNullWhenFieldsMissing(): void
    {
        @session_start();
        $_SESSION = ['partial_key' => ['state' => 'only-state']];

        $store = new SessionOAuthFlowStateStore('partial_key');

        $this->assertNull($store->pull());
    }
}
