<?php

declare(strict_types=1);

namespace Amtgard\IdpClient\ClientIam\Model;

final readonly class ServiceFormatRequest
{
    /**
     * @param list<string> $serviceFormat
     */
    public function __construct(
        public array $serviceFormat,
    ) {}
}
