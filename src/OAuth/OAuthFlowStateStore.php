<?php

declare(strict_types=1);

namespace Amtgard\IdpClient\OAuth;

interface OAuthFlowStateStore
{
    public function put(OAuthFlowState $state): void;

    /** Read-once (flash semantics). */
    public function pull(): ?OAuthFlowState;
}
