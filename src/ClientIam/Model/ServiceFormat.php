<?php

declare(strict_types=1);

namespace Amtgard\IdpClient\ClientIam\Model;

final readonly class ServiceFormat
{
    /**
     * @param list<string> $serviceFormat
     */
    public function __construct(
        public ?string $iamService,
        public array $serviceFormat,
        public bool $isDefault,
    ) {}

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        $slots = $data['service_format'] ?? [];
        if (!is_array($slots)) {
            $slots = [];
        }

        return new self(
            iamService: isset($data['iam_service']) && is_string($data['iam_service']) ? $data['iam_service'] : null,
            serviceFormat: array_values(array_map(static fn (mixed $slot): string => (string) $slot, $slots)),
            isDefault: (bool) ($data['is_default'] ?? false),
        );
    }
}
