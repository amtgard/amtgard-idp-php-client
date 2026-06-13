<?php

declare(strict_types=1);

namespace Amtgard\IdpClient\ClientIam\Iam;

use Amtgard\IAM\Allowance\Claim;
use Amtgard\IAM\Resource;

final class IntegratorClaim extends Claim
{
    protected function serviceFormat(): array
    {
        return IntegratorFormatRegistry::get(
            IntegratorFormatRegistry::currentService() ?? $this->getServiceIdentifier()->name,
        );
    }

    /** @return array<string, list<string>> */
    protected function getResourceMap(?string $resource = null): array
    {
        return ['*' => ['*']];
    }

    protected function validResource(Resource $resource): bool
    {
        return true;
    }
}
