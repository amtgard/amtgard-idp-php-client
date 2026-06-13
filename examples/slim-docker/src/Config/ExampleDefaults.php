<?php

declare(strict_types=1);

namespace Amtgard\IdpSlimExample\Config;

/**
 * Demo defaults for authorization check and Client IAM compose-claim in this example app.
 *
 * Override via .env: EXAMPLE_POLICY, EXAMPLE_POLICY_REQUIREMENT, EXAMPLE_CLIENT_IAM_RESOURCE,
 * EXAMPLE_CLIENT_IAM_SEGMENTS.
 */
final class ExampleDefaults
{
    public const POLICY_REQUIREMENT = 'Idp:0:0:0:0:IDP/EditClient';

    /** @var list<string> */
    public const POLICY_ORNS = ['Idp:0:0:0:0:IDP/EditClient'];

    public const CLIENT_IAM_RESOURCE = 'IDP/EditClient';

    /** @var array<string, int|string|null> */
    public const CLIENT_IAM_SEGMENTS = [];

    public static function policyRequirement(): string
    {
        $value = getenv('EXAMPLE_POLICY_REQUIREMENT');

        return is_string($value) && $value !== '' ? $value : self::POLICY_REQUIREMENT;
    }

    /**
     * @return list<string>
     */
    public static function policyOrns(): array
    {
        $json = getenv('EXAMPLE_POLICY');
        if (!is_string($json) || $json === '') {
            return self::POLICY_ORNS;
        }

        try {
            $decoded = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return self::POLICY_ORNS;
        }

        if (!is_array($decoded)) {
            return self::POLICY_ORNS;
        }

        $orns = [];
        foreach ($decoded as $orn) {
            if (is_string($orn) && $orn !== '') {
                $orns[] = $orn;
            }
        }

        return $orns;
    }

    public static function policyOrnsJson(): string
    {
        return json_encode(self::policyOrns(), JSON_THROW_ON_ERROR);
    }

    public static function clientIamResource(): string
    {
        $value = getenv('EXAMPLE_CLIENT_IAM_RESOURCE');

        return is_string($value) && $value !== '' ? $value : self::CLIENT_IAM_RESOURCE;
    }

    /**
     * @return array<string, int|string|null>
     */
    public static function clientIamSegments(): array
    {
        $json = getenv('EXAMPLE_CLIENT_IAM_SEGMENTS');
        if (!is_string($json) || $json === '') {
            return self::CLIENT_IAM_SEGMENTS;
        }

        try {
            $decoded = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return self::CLIENT_IAM_SEGMENTS;
        }

        return is_array($decoded) ? $decoded : self::CLIENT_IAM_SEGMENTS;
    }

    public static function clientIamSegmentsJson(): string
    {
        return json_encode(self::clientIamSegments(), JSON_THROW_ON_ERROR);
    }
}
