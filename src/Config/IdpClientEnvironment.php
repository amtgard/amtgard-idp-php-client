<?php

declare(strict_types=1);

namespace Amtgard\IdpClient\Config;

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

    /** Outbound User-Agent for all server-side IDP HTTP (token exchange + resources). */
    public function httpUserAgent(): string;

    /** Offline IAM service prefix for Client IAM validation without GET. */
    public function iamService(): ?string;

    /**
     * Offline service format slot names (JSON array in env) for Client IAM without GET.
     *
     * @return list<string>|null
     */
    public function iamServiceFormat(): ?array;
}
