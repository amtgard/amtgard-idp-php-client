<?php

declare(strict_types=1);

namespace Amtgard\IdpClient;

final class Pkce
{
    public static function generateVerifier(): string
    {
        return self::base64UrlEncode(random_bytes(32));
    }

    public static function challengeFromVerifier(string $verifier): string
    {
        return self::base64UrlEncode(hash('sha256', $verifier, true));
    }

    public static function generateState(): string
    {
        return bin2hex(random_bytes(16));
    }

    public static function base64UrlEncode(string $data): string
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }
}
