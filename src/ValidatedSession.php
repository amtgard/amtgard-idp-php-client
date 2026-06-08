<?php

declare(strict_types=1);

namespace Amtgard\IdpClient;

/**
 * Response from GET /resources/validate — session presence heartbeat with core identity fields.
 */
final readonly class ValidatedSession
{
    public function __construct(
        public int $id,
        public string $email,
        public string $jwt,
    ) {}

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            id: (int) ($data['id'] ?? 0),
            email: (string) ($data['email'] ?? ''),
            jwt: (string) ($data['jwt'] ?? ''),
        );
    }
}
