<?php

declare(strict_types=1);

namespace Amtgard\IdpClient;

final readonly class OAuthFlowState
{
    public function __construct(
        public string $state,
        public string $codeVerifier,
        public ?string $returnTo = null,
    ) {}
}
