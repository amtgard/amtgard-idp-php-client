<?php

declare(strict_types=1);

namespace Amtgard\IdpClient\Resource;

final readonly class OrkProfile
{
    public function __construct(
        public int $mundaneId,
        public string $username,
        public string $persona,
        public bool $suspended,
        public ?string $suspendedAt,
        public ?string $suspendedUntil,
        public ?int $parkId,
        public ?string $parkName,
        public ?int $kingdomId,
        public ?string $kingdomName,
        public ?string $image,
        public ?string $heraldry,
        public ?string $duesThrough,
    ) {}

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            mundaneId: (int) ($data['mundane_id'] ?? 0),
            username: (string) ($data['username'] ?? ''),
            persona: (string) ($data['persona'] ?? ''),
            suspended: (bool) ($data['suspended'] ?? false),
            suspendedAt: isset($data['suspended_at']) ? (string) $data['suspended_at'] : null,
            suspendedUntil: isset($data['suspended_until']) ? (string) $data['suspended_until'] : null,
            parkId: isset($data['park_id']) ? (int) $data['park_id'] : null,
            parkName: isset($data['park_name']) ? (string) $data['park_name'] : null,
            kingdomId: isset($data['kingdom_id']) ? (int) $data['kingdom_id'] : null,
            kingdomName: isset($data['kingdom_name']) ? (string) $data['kingdom_name'] : null,
            image: isset($data['image']) ? (string) $data['image'] : null,
            heraldry: isset($data['heraldry']) ? (string) $data['heraldry'] : null,
            duesThrough: isset($data['dues_through']) ? (string) $data['dues_through'] : null,
        );
    }
}
