<?php

declare(strict_types=1);

namespace Amtgard\IdpClient\Config;

use Amtgard\IdpClient\Exception\IdpConfigurationException;

final class IdpClientEnvironmentFactory
{
    private const REQUIRED = [
        'IDP_BASE_URL',
        'IDP_CLIENT_ID',
        'IDP_REDIRECT_URI',
    ];

    /**
     * Build an environment from $_ENV / getenv() when $env is omitted.
     *
     * @param array<string, mixed>|null $env
     */
    public static function fromEnvVars(?array $env = null): IdpClientEnvironment
    {
        $env ??= $_ENV;
        $missing = [];

        foreach (self::REQUIRED as $key) {
            if (!self::hasNonEmptyString($env, $key)) {
                $missing[] = $key;
            }
        }

        if ($missing !== []) {
            throw new IdpConfigurationException($missing);
        }

        $secret = self::optionalString($env, 'IDP_CLIENT_SECRET');

        return new EnvIdpClientEnvironment(
            idpBaseUrl: rtrim(self::requiredString($env, 'IDP_BASE_URL'), '/'),
            clientId: self::requiredString($env, 'IDP_CLIENT_ID'),
            clientSecret: $secret,
            redirectUri: self::requiredString($env, 'IDP_REDIRECT_URI'),
            httpUserAgent: self::optionalString($env, 'IDP_HTTP_USER_AGENT')
                ?? IdpClientEnvironment::DEFAULT_HTTP_USER_AGENT,
            iamService: self::optionalString($env, 'IDP_IAM_SERVICE'),
            iamServiceFormat: self::optionalStringList($env, 'IDP_IAM_SERVICE_FORMAT'),
        );
    }

    /**
     * @param array<string, mixed> $env
     */
    private static function hasNonEmptyString(array $env, string $key): bool
    {
        $value = $env[$key] ?? getenv($key);

        return is_string($value) && $value !== '';
    }

    /**
     * @param array<string, mixed> $env
     */
    private static function requiredString(array $env, string $key): string
    {
        $value = $env[$key] ?? getenv($key);

        return is_string($value) ? $value : '';
    }

    /**
     * @param array<string, mixed> $env
     */
    private static function optionalString(array $env, string $key): ?string
    {
        $value = $env[$key] ?? getenv($key);

        return is_string($value) && $value !== '' ? $value : null;
    }

    /**
     * @param array<string, mixed> $env
     *
     * @return list<string>|null
     */
    private static function optionalStringList(array $env, string $key): ?array
    {
        $value = $env[$key] ?? getenv($key);
        if (!is_string($value) || trim($value) === '') {
            return null;
        }

        $decoded = json_decode($value, true);
        if (!is_array($decoded) || $decoded === [] || array_is_list($decoded) === false) {
            throw new IdpConfigurationException([$key]);
        }

        $list = [];
        foreach ($decoded as $item) {
            if (!is_string($item) || $item === '') {
                throw new IdpConfigurationException([$key]);
            }
            $list[] = $item;
        }

        return $list;
    }
}
