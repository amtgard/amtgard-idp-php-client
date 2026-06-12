<?php

declare(strict_types=1);

namespace Amtgard\IdpClient\Iam;

/**
 * Result of IAM policy evaluation via {@see AuthorizationEvaluator}.
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
