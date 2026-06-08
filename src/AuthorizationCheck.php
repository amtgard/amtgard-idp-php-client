<?php

declare(strict_types=1);

namespace Amtgard\IdpClient;

/**
 * Response from POST /api/is_authorized — IAM policy evaluation for backend services.
 */
final readonly class AuthorizationCheck
{
    public function __construct(
        public bool $isAuthorized,
    ) {}

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            isAuthorized: (bool) ($data['is_authorized'] ?? false),
        );
    }
}
