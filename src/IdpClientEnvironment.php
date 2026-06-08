<?php

declare(strict_types=1);

namespace Amtgard\IdpClient;

interface IdpClientEnvironment
{
    public const DEFAULT_HTTP_USER_AGENT = 'AmtgardIDP/1.0';

    /** e.g. https://idp.amtgard.com (no trailing slash) */
    public function idpBaseUrl(): string;

    public function clientId(): string;

    /** null => public client (PKCE required); non-null => confidential client */
    public function clientSecret(): ?string;

    public function redirectUri(): string;

    /** @return list<string> */
    public function scopes(): array;

    /**
     * Outbound User-Agent for all server-side IDP HTTP (token exchange + resources).
     * Default: {@see self::DEFAULT_HTTP_USER_AGENT} (`AmtgardIDP/1.0`).
     */
    public function httpUserAgent(): string;
}
