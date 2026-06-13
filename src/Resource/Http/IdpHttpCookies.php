<?php

declare(strict_types=1);

namespace Amtgard\IdpClient\Resource\Http;

use Psr\Http\Message\ResponseInterface;

/**
 * Captures and replays IDP Set-Cookie headers across server-side resource calls.
 *
 * Required for GET /resources/validate, which checks $_SESSION['user_id'] on the IDP host.
 */
final class IdpHttpCookies
{
    /** @var array<string, string> */
    private array $cookies = [];

    public static function fromHeader(?string $cookieHeader): self
    {
        $jar = new self();
        if ($cookieHeader === null || $cookieHeader === '') {
            return $jar;
        }

        foreach (explode(';', $cookieHeader) as $part) {
            $part = trim($part);
            if ($part === '') {
                continue;
            }

            $equalsAt = strpos($part, '=');
            if ($equalsAt === false) {
                continue;
            }

            $jar->cookies[substr($part, 0, $equalsAt)] = substr($part, $equalsAt + 1);
        }

        return $jar;
    }

    public function hasCookies(): bool
    {
        return $this->cookies !== [];
    }

    public function toHeader(): ?string
    {
        if ($this->cookies === []) {
            return null;
        }

        $parts = [];
        foreach ($this->cookies as $name => $value) {
            $parts[] = $name . '=' . $value;
        }

        return implode('; ', $parts);
    }

    public function absorbFromResponse(ResponseInterface $response): void
    {
        $this->absorbSetCookieHeaders($response->getHeader('Set-Cookie'));
    }

    /**
     * @param list<string> $setCookieHeaders
     */
    public function absorbSetCookieHeaders(array $setCookieHeaders): void
    {
        foreach ($setCookieHeaders as $header) {
            $pair = explode(';', $header, 2)[0];
            $equalsAt = strpos($pair, '=');
            if ($equalsAt === false) {
                continue;
            }

            $this->cookies[trim(substr($pair, 0, $equalsAt))] = trim(substr($pair, $equalsAt + 1));
        }
    }
}
