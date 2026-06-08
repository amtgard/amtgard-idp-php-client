<?php

declare(strict_types=1);

namespace Amtgard\IdpClient;

interface IdpClientEnvironment
{
    /** e.g. https://idp.amtgard.com (no trailing slash) */
    public function idpBaseUrl(): string;

    public function clientId(): string;

    /** null => public client (PKCE required); non-null => confidential client */
    public function clientSecret(): ?string;

    public function redirectUri(): string;

    /** @return list<string> */
    public function scopes(): array;

    /**
     * Outbound User-Agent for all server-side IDP HTTP (token exchange + resources; not ORK API).
     * Default: amtgard-idp-php-client/1.0
     */
    public function httpUserAgent(): string;
}
