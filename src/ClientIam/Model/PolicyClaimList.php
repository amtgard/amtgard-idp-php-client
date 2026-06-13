<?php

declare(strict_types=1);

namespace Amtgard\IdpClient\ClientIam\Model;

final readonly class PolicyClaimList
{
    /**
     * @param list<PolicyClaim> $claims
     */
    public function __construct(
        public array $claims,
    ) {}

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        $claims = [];
        $raw = $data['claims'] ?? [];
        if (is_array($raw)) {
            foreach ($raw as $item) {
                if (is_array($item)) {
                    $claims[] = PolicyClaim::fromArray($item);
                }
            }
        }

        return new self($claims);
    }
}
