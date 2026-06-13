<?php

declare(strict_types=1);

namespace Amtgard\IdpClient\Iam;

use Amtgard\IAM\Allowance\Claim;
use Amtgard\IAM\ORN\OrnSegmentLabel;
use Amtgard\IAM\OrkServices;

final class OrnWireFormat
{
    /**
     * @param list<OrnSegmentLabel|OrkServices|string> $schema
     * @param array<string, int|string|null> $segments
     */
    public static function composeFullOrn(
        string $prefix,
        array $schema,
        array $segments,
        string $resource,
    ): string {
        self::assertKnownSegmentKeys($schema, $segments);

        $segmentValues = self::segmentValuesForSchema($schema, $segments);
        $resourcePart = self::normalizeResource($resource);

        return $prefix . ':' . implode(':', $segmentValues) . ':' . $resourcePart;
    }

    public static function decompose(string $fullOrn, int $schemaSlotCount): OrnWireParts
    {
        $colonPos = strpos($fullOrn, ':');
        if ($colonPos === false) {
            throw new \InvalidArgumentException('ORN must contain a prefix and proviso segments separated by colons.');
        }

        $prefix = substr($fullOrn, 0, $colonPos);
        $rest = substr($fullOrn, $colonPos + 1);
        $parts = explode(':', $rest, $schemaSlotCount + 1);

        if (count($parts) < $schemaSlotCount + 1) {
            throw new \InvalidArgumentException('ORN is missing proviso segments or a resource.');
        }

        $segmentValues = array_slice($parts, 0, $schemaSlotCount);
        while (count($segmentValues) < $schemaSlotCount) {
            $segmentValues[] = '';
        }

        $resource = $parts[$schemaSlotCount];
        if ($resource === '') {
            throw new \InvalidArgumentException('ORN is missing a resource segment.');
        }

        $provisos = ':' . implode(':', $segmentValues) . ':';

        return new OrnWireParts($prefix, $provisos, $resource);
    }

    public static function fromClaim(Claim $claim): OrnWireParts
    {
        return self::decompose($claim->buildOrn(), count($claim->ornSegmentSchema()));
    }

    /**
     * @param list<OrnSegmentLabel|OrkServices|string> $schema
     * @param array<string, int|string|null> $segments
     *
     * @return list<string>
     */
    private static function segmentValuesForSchema(array $schema, array $segments): array
    {
        return array_map(
            static function ($label) use ($segments): string {
                $name = ($label instanceof OrnSegmentLabel ? $label : OrnSegmentLabel::from($label))->name;
                if (!array_key_exists($name, $segments)) {
                    return '';
                }

                $value = $segments[$name];

                return $value === '' || $value === null ? '' : (string) $value;
            },
            $schema,
        );
    }

    /**
     * @param list<OrnSegmentLabel|OrkServices|string> $schema
     * @param array<string, int|string|null> $segments
     */
    private static function assertKnownSegmentKeys(array $schema, array $segments): void
    {
        $allowed = [];
        foreach ($schema as $label) {
            $allowed[($label instanceof OrnSegmentLabel ? $label : OrnSegmentLabel::from($label))->name] = true;
        }

        foreach (array_keys($segments) as $key) {
            if (!isset($allowed[$key])) {
                throw new \InvalidArgumentException(sprintf('Unknown ORN segment key "%s".', $key));
            }
        }
    }

    private static function normalizeResource(string $resource): string
    {
        return $resource;
    }
}
