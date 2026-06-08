<?php

declare(strict_types=1);

namespace Amtgard\IdpClient;

final class InMemoryOAuthFlowStateStore implements OAuthFlowStateStore
{
    private ?OAuthFlowState $state = null;

    public function put(OAuthFlowState $state): void
    {
        $this->state = $state;
    }

    public function pull(): ?OAuthFlowState
    {
        $state = $this->state;
        $this->state = null;

        return $state;
    }
}
