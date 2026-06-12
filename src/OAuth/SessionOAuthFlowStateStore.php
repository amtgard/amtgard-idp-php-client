<?php

declare(strict_types=1);

namespace Amtgard\IdpClient\OAuth;

final class SessionOAuthFlowStateStore implements OAuthFlowStateStore
{
    private const SESSION_KEY = 'amtgard_idp_oauth_flow_state';

    public function __construct(
        private readonly string $sessionKey = self::SESSION_KEY,
    ) {}

    public function put(OAuthFlowState $state): void
    {
        $_SESSION[$this->sessionKey] = [
            'state' => $state->state,
            'code_verifier' => $state->codeVerifier,
            'return_to' => $state->returnTo,
        ];
    }

    public function pull(): ?OAuthFlowState
    {
        if (!isset($_SESSION[$this->sessionKey])) {
            return null;
        }

        $stored = $_SESSION[$this->sessionKey];
        unset($_SESSION[$this->sessionKey]);

        if (!is_array($stored)) {
            return null;
        }

        $state = $stored['state'] ?? null;
        $codeVerifier = $stored['code_verifier'] ?? null;

        if (!is_string($state) || !is_string($codeVerifier)) {
            return null;
        }

        $returnTo = $stored['return_to'] ?? null;

        return new OAuthFlowState(
            $state,
            $codeVerifier,
            is_string($returnTo) ? $returnTo : null,
        );
    }
}
