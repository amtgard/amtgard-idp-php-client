<?php

declare(strict_types=1);

namespace Amtgard\IdpClient\Iam;

use Amtgard\IAM\Catalog\ServiceCatalog;
use Amtgard\IAM\ORN\OrnClassMap;
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

        if (!OrnClassMap::isRegistered(ServiceCatalog::Idp)) {
            OrnClassMap::registerClaim(ServiceCatalog::Idp, IdpClaim::class);
        }

        if (!OrnClassMap::isRegistered(ServiceCatalog::Idp, asRequirement: true)) {
            OrnClassMap::registerRequirement(ServiceCatalog::Idp, IdpRequirement::class);
        }

        self::$registered = true;
    }

    /** @internal */
    public static function reset(): void
    {
        self::$registered = false;
    }
}
