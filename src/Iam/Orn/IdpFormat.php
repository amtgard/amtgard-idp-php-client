<?php

declare(strict_types=1);

namespace Amtgard\IdpClient\Iam\Orn;

use Amtgard\IAM\ORNFormat;
use Amtgard\IAM\OrkServices;

final class IdpFormat extends ORNFormat
{
    public static function serviceFormat(): array
    {
        return [
            OrkServices::Configuration,
            OrkServices::Game,
            OrkServices::Kingdom,
            OrkServices::Park,
        ];
    }

    public static function getValidResourceMap($resource = null): array
    {
        $map = [
            'IDP' => ['EditClient', 'EditIdentity'],
        ];

        return $resource ? $map[$resource] : $map;
    }
}
