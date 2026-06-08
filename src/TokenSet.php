<?php

declare(strict_types=1);

namespace Amtgard\IdpClient;

final readonly class TokenSet
{
    public function __construct(
        public string $accessToken,
        public ?string $refreshToken = null,
        public ?\DateTimeImmutable $expiresAt = null,
    ) {}

    public function accessToken(): string
    {
        return $this->accessToken;
    }

    public function refreshToken(): ?string
    {
        return $this->refreshToken;
    }

    public function expiresAt(): ?\DateTimeImmutable
    {
        return $this->expiresAt;
    }

    public function isExpired(\DateTimeImmutable $now = new \DateTimeImmutable()): bool
    {
        if ($this->expiresAt === null) {
            return false;
        }

        return $this->expiresAt <= $now;
    }
}
