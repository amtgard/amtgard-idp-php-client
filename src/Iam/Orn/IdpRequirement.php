<?php

declare(strict_types=1);

namespace Amtgard\IdpClient\Iam\Orn;

use Amtgard\IAM\Requirement\Requirement;

final class IdpRequirement extends Requirement
{
    public function ornSegmentSchema(): array
    {
        return IdpFormat::ornSegmentSchema();
    }

    /** @return array<string, list<string>>|list<string> */
    protected function getResourceMap(?string $resource = null): array
    {
        return IdpFormat::getValidResourceMap($resource);
    }
}
