<?php

declare(strict_types=1);

namespace Amtgard\IdpClient\OAuth;

final readonly class AuthorizationResult
{
    public function __construct(
        public TokenSet $tokens,
        public ?string $returnTo = null,
    ) {}
}
