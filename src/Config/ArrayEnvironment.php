<?php

declare(strict_types=1);

namespace Amtgard\IdpClient\Config;

final readonly class ArrayEnvironment implements IdpClientEnvironment
{
    /**
     * @param list<string> $scopes
     */
    public function __construct(
        private string $idpBaseUrl,
        private string $clientId,
        private ?string $clientSecret,
        private string $redirectUri,
        private array $scopes = ['profile', 'email'],
        private string $httpUserAgent = IdpClientEnvironment::DEFAULT_HTTP_USER_AGENT,
        private ?string $iamService = null,
        /** @var list<string>|null */
        private ?array $iamServiceFormat = null,
    ) {}

    public function idpBaseUrl(): string
    {
        return $this->idpBaseUrl;
    }

    public function clientId(): string
    {
        return $this->clientId;
    }

    public function clientSecret(): ?string
    {
        return $this->clientSecret;
    }

    public function redirectUri(): string
    {
        return $this->redirectUri;
    }

    public function scopes(): array
    {
        return $this->scopes;
    }

    public function httpUserAgent(): string
    {
        return $this->httpUserAgent;
    }

    public function iamService(): ?string
    {
        return $this->iamService;
    }

    public function iamServiceFormat(): ?array
    {
        return $this->iamServiceFormat;
    }
}
