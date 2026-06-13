<?php

declare(strict_types=1);

namespace Amtgard\IdpClient\Iam;

use Amtgard\IAM\OrkServices;
use Amtgard\IAM\ORN\OrnSegmentLabel;

final class ServiceFormatParser
{
    /**
     * @return list<OrkServices|string>
     */
    public static function parse(?string $json): array
    {
        if ($json === null || trim($json) === '') {
            return self::defaultFormat();
        }

        $decoded = json_decode($json, true);
        if (!is_array($decoded) || $decoded === [] || array_is_list($decoded) === false) {
            throw new \InvalidArgumentException('iam_service_format must be a JSON array of proviso slot names.');
        }

        $format = [];
        foreach ($decoded as $slot) {
            if (!is_string($slot) || trim($slot) === '') {
                throw new \InvalidArgumentException('iam_service_format entries must be non-empty strings.');
            }

            $label = OrnSegmentLabel::from(trim($slot));
            $format[] = $label->toOrkServices() ?? $label->name;
        }

        return $format;
    }

    /**
     * @param list<string|OrkServices> $slots
     *
     * @return list<OrkServices|string>
     */
    public static function parseList(array $slots): array
    {
        return self::parse(json_encode($slots, JSON_THROW_ON_ERROR));
    }

    /**
     * @return list<OrkServices|string>
     */
    public static function defaultFormat(): array
    {
        return [
            OrkServices::Configuration,
            OrkServices::Game,
            OrkServices::Kingdom,
            OrkServices::Park,
        ];
    }

    /**
     * @param list<OrkServices|string> $format
     *
     * @return list<string>
     */
    public static function slotNames(array $format): array
    {
        return array_map(
            static fn (OrkServices|string $slot): string => $slot instanceof OrkServices ? $slot->value : $slot,
            $format,
        );
    }
}
