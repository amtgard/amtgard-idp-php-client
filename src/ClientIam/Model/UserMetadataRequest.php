<?php

declare(strict_types=1);

namespace Amtgard\IdpClient\ClientIam\Model;

final readonly class UserMetadataRequest
{
    /**
     * @param array<string, mixed>|string $metadata JSON object when encoding=json; base64 string when encoding=base64
     */
    public function __construct(
        public string $idpUserId,
        public int $loginId,
        public array|string $metadata,
        public string $encoding = 'json',
    ) {}
}
