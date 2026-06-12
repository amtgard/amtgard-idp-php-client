<?php

declare(strict_types=1);

namespace Amtgard\IdpClient\Iam;

use Amtgard\IAM\ORN\OrnClassMap;
use Amtgard\IAM\OrkServices;
use Amtgard\IdpClient\Iam\Orn\IdpClaim;
use Amtgard\IdpClient\Iam\Orn\IdpRequirement;

/**
 * Registers ORN classes not covered by amtgard/ork-iam-orn-definitions.
 *
 * ORK and Attendance prefixes are registered via that package's register.php autoload.
 */
final class OrnBootstrap
{
    private static bool $registered = false;

    public static function register(): void
    {
        if (self::$registered) {
            return;
        }

        if (!OrnClassMap::isRegistered(OrkServices::Idp)) {
            OrnClassMap::registerClaim(OrkServices::Idp, IdpClaim::class);
        }

        if (!OrnClassMap::isRegistered(OrkServices::Idp, asRequirement: true)) {
            OrnClassMap::registerRequirement(OrkServices::Idp, IdpRequirement::class);
        }

        self::$registered = true;
    }

    /** @internal */
    public static function reset(): void
    {
        self::$registered = false;
    }
}
