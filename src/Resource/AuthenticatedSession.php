<?php

declare(strict_types=1);

namespace Amtgard\IdpClient\Resource;

use Amtgard\IdpClient\OAuth\TokenSet;

final readonly class AuthenticatedSession
{
    public function __construct(
        public TokenSet $tokens,
        public UserProfile $profile,
        public ?string $returnTo = null,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toSessionArray(): array
    {
        $payload = [
            'access_token' => $this->tokens->accessToken(),
            'refresh_token' => $this->tokens->refreshToken(),
            'expires_at' => $this->tokens->expiresAt()?->format(\DateTimeInterface::ATOM),
            'return_to' => $this->returnTo,
            'profile' => [
                'id' => $this->profile->id,
                'email' => $this->profile->email,
                'jwt' => $this->profile->jwt,
            ],
        ];

        if ($this->profile->orkProfile !== null) {
            $ork = $this->profile->orkProfile;
            $payload['profile']['ork_profile'] = [
                'mundane_id' => $ork->mundaneId,
                'username' => $ork->username,
                'persona' => $ork->persona,
                'suspended' => $ork->suspended,
                'suspended_at' => $ork->suspendedAt,
                'suspended_until' => $ork->suspendedUntil,
                'park_id' => $ork->parkId,
                'park_name' => $ork->parkName,
                'kingdom_id' => $ork->kingdomId,
                'kingdom_name' => $ork->kingdomName,
                'image' => $ork->image,
                'heraldry' => $ork->heraldry,
                'dues_through' => $ork->duesThrough,
            ];
        }

        return $payload;
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function fromSessionArray(array $data): self
    {
        $expiresAt = null;
        if (isset($data['expires_at']) && is_string($data['expires_at']) && $data['expires_at'] !== '') {
            $expiresAt = new \DateTimeImmutable($data['expires_at']);
        }

        $profileData = is_array($data['profile'] ?? null) ? $data['profile'] : [];

        return new self(
            tokens: new TokenSet(
                (string) ($data['access_token'] ?? ''),
                isset($data['refresh_token']) ? (string) $data['refresh_token'] : null,
                $expiresAt,
            ),
            profile: UserProfile::fromArray($profileData),
            returnTo: isset($data['return_to']) && is_string($data['return_to']) ? $data['return_to'] : null,
        );
    }
}
