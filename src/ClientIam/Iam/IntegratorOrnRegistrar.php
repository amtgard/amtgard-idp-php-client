<?php

declare(strict_types=1);

namespace Amtgard\IdpClient\ClientIam\Iam;

use Amtgard\IAM\ORN\OrnClassMap;
use Amtgard\IAM\OrkServices;

final class IntegratorOrnRegistrar
{
    /**
     * @param list<OrkServices|string> $format
     */
    public static function register(string $service, array $format): void
    {
        IntegratorFormatRegistry::register($service, $format);

        if ($service === OrkServices::Idp->value) {
            return;
        }

        if (OrnClassMap::isRegistered($service)) {
            return;
        }

        if (OrkServices::tryFrom($service) !== null) {
            return;
        }

        OrnClassMap::registerClaim($service, IntegratorClaim::class);
    }

    /** @internal */
    public static function reset(): void
    {
        IntegratorFormatRegistry::reset();
    }
}
