<?php

declare(strict_types=1);

namespace Amtgard\IdpClient\ClientIam\Iam;

use Amtgard\IAM\Catalog\ServiceCatalog;
use Amtgard\IAM\ORN\OrnClassMap;

final class IntegratorOrnRegistrar
{
    /**
     * @param list<ServiceCatalog|string> $format
     */
    public static function register(string $service, array $format): void
    {
        IntegratorFormatRegistry::register($service, $format);

        if (strcasecmp($service, ServiceCatalog::Idp->value) === 0) {
            return;
        }

        if (OrnClassMap::isRegistered($service)) {
            return;
        }

        if (ServiceCatalog::tryFrom($service) !== null) {
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
