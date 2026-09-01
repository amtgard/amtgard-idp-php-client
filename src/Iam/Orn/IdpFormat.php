<?php

declare(strict_types=1);

namespace Amtgard\IdpClient\Iam\Orn;

use Amtgard\IAM\Catalog\ServiceCatalog;
use Amtgard\IAM\ORNFormat;

final class IdpFormat extends ORNFormat
{
    public static function ornSegmentSchema(): array
    {
        return [
            ServiceCatalog::Configuration,
            ServiceCatalog::Game,
            ServiceCatalog::Kingdom,
            ServiceCatalog::Park,
        ];
    }

    /**
     * @param string|null $resource
     * @return array<string, list<string>>|list<string>
     */
    public static function getValidResourceMap($resource = null): array
    {
        $map = [
            'IDP' => ['EditClient', 'EditIdentity'],
        ];

        return $resource ? $map[$resource] : $map;
    }
}
