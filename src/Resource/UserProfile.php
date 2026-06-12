<?php

declare(strict_types=1);

namespace Amtgard\IdpClient\Resource;

final readonly class UserProfile
{
    public function __construct(
        public int $id,
        public string $email,
        public string $jwt,
        public ?OrkProfile $orkProfile = null,
    ) {}

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        $orkProfile = null;
        if (isset($data['ork_profile']) && is_array($data['ork_profile'])) {
            $orkProfile = OrkProfile::fromArray($data['ork_profile']);
        }

        return new self(
            id: (int) ($data['id'] ?? 0),
            email: (string) ($data['email'] ?? ''),
            jwt: (string) ($data['jwt'] ?? ''),
            orkProfile: $orkProfile,
        );
    }
}
