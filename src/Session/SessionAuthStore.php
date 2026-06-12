<?php

declare(strict_types=1);

namespace Amtgard\IdpClient\Session;

use Amtgard\IdpClient\Resource\AuthenticatedSession;

final class SessionAuthStore
{
    private const SESSION_KEY = 'amtgard_idp_authenticated_session';

    public function __construct(
        private readonly string $sessionKey = self::SESSION_KEY,
    ) {}

    public function store(AuthenticatedSession $session): void
    {
        $_SESSION[$this->sessionKey] = $session->toSessionArray();
    }

    public function get(): ?AuthenticatedSession
    {
        if (!isset($_SESSION[$this->sessionKey]) || !is_array($_SESSION[$this->sessionKey])) {
            return null;
        }

        return AuthenticatedSession::fromSessionArray($_SESSION[$this->sessionKey]);
    }

    public function clear(): void
    {
        unset($_SESSION[$this->sessionKey]);
    }

    public function isAuthenticated(): bool
    {
        return $this->get() !== null;
    }
}
