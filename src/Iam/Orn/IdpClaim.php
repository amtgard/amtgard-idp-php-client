<?php

declare(strict_types=1);

namespace Amtgard\IdpClient\Iam\Orn;

use Amtgard\IAM\Allowance\Claim;

final class IdpClaim extends Claim
{
    public function ornSegmentSchema(): array
    {
        return IdpFormat::ornSegmentSchema();
    }

    protected function getResourceMap(?string $resource = null): array
    {
        return IdpFormat::getValidResourceMap($resource);
    }
}
