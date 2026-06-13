<?php

declare(strict_types=1);

namespace Amtgard\IdpClient\Iam;

final readonly class OrnWireParts
{
    public function __construct(
        public string $prefix,
        public string $provisos,
        public string $resource,
    ) {}

    public function fullOrn(): string
    {
        return $this->prefix . $this->provisos . $this->resource;
    }
}
