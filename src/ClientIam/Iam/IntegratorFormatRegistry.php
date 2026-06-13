<?php

declare(strict_types=1);

namespace Amtgard\IdpClient\ClientIam\Iam;

use Amtgard\IAM\OrkServices;

final class IntegratorFormatRegistry
{
    /** @var array<string, list<OrkServices|string>> */
    private static array $formats = [];

    private static ?string $currentService = null;

    /**
     * @param list<OrkServices|string> $format
     */
    public static function register(string $service, array $format): void
    {
        self::$formats[$service] = $format;
        self::$currentService = $service;
    }

    /**
     * @return list<OrkServices|string>
     */
    public static function get(string $service): array
    {
        if (!isset(self::$formats[$service])) {
            throw new \RuntimeException(sprintf('No integrator service format registered for "%s".', $service));
        }

        return self::$formats[$service];
    }

    public static function has(string $service): bool
    {
        return isset(self::$formats[$service]);
    }

    public static function currentService(): ?string
    {
        return self::$currentService;
    }

    /** @internal */
    public static function reset(): void
    {
        self::$formats = [];
        self::$currentService = null;
    }
}
