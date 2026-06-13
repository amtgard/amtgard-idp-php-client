<?php

declare(strict_types=1);

namespace Amtgard\IdpClient\ClientIam\Model;

final readonly class PolicyClaim
{
    public function __construct(
        public string $service,
        public string $provisos,
        public string $resource,
    ) {}

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            service: (string) ($data['service'] ?? ''),
            provisos: (string) ($data['provisos'] ?? ''),
            resource: (string) ($data['resource'] ?? ''),
        );
    }

    public function fullOrn(): string
    {
        return $this->service . $this->provisos . $this->resource;
    }
}
