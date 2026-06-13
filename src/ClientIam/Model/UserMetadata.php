<?php

declare(strict_types=1);

namespace Amtgard\IdpClient\ClientIam\Model;

final readonly class UserMetadata
{
    /**
     * @param array<string, mixed>|string $metadata
     */
    public function __construct(
        public int $loginId,
        public array|string $metadata,
        public string $encoding,
    ) {}

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        $encoding = (string) ($data['encoding'] ?? 'json');
        $metadata = $data['metadata'] ?? [];

        if ($encoding === 'base64') {
            $metadata = is_string($metadata) ? $metadata : '';
        } elseif (!is_array($metadata)) {
            $metadata = [];
        }

        return new self(
            loginId: (int) ($data['login_id'] ?? 0),
            metadata: $metadata,
            encoding: $encoding,
        );
    }
}
