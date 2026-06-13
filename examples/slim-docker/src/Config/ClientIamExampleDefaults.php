<?php

declare(strict_types=1);

namespace Amtgard\IdpSlimExample\Config;

use Amtgard\IdpClient\ClientIam\Model\ServiceFormat;

/**
 * Derives compose-claim demo inputs from GET /resources/client/service-format.
 */
final class ClientIamExampleDefaults
{
    public static function resolvedIamService(ServiceFormat $format): ?string
    {
        if ($format->iamService !== null && $format->iamService !== '') {
            return $format->iamService;
        }

        if ($format->isDefault) {
            return 'Idp';
        }

        return null;
    }

    /**
     * @param list<string> $serviceFormat
     *
     * @return array<string, int>
     */
    public static function segmentsForServiceFormat(array $serviceFormat): array
    {
        $segments = [];
        foreach ($serviceFormat as $slot) {
            if (is_string($slot) && $slot !== '') {
                $segments[$slot] = 0;
            }
        }

        return $segments;
    }

    public static function resourceForIamService(?string $iamService): string
    {
        if ($iamService === null || strcasecmp($iamService, 'Idp') === 0) {
            return ExampleDefaults::CLIENT_IAM_RESOURCE;
        }

        return 'Editor/Write';
    }

    /**
     * @return array{segments: array<string, int>, resource: string, iam_service: string|null}
     */
    public static function fromServiceFormat(ServiceFormat $format): array
    {
        $iamService = self::resolvedIamService($format);

        return [
            'iam_service' => $iamService,
            'segments' => self::segmentsForServiceFormat($format->serviceFormat),
            'resource' => self::resourceForIamService($iamService),
        ];
    }
}
