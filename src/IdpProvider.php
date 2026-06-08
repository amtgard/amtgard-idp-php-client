<?php

declare(strict_types=1);

namespace Amtgard\IdpClient;

use League\OAuth2\Client\Provider\GenericProvider;

final class IdpProvider extends GenericProvider
{
    public static function fromEnvironment(IdpClientEnvironment $environment): self
    {
        return new self([
            'clientId' => $environment->clientId(),
            'clientSecret' => $environment->clientSecret() ?? '',
            'redirectUri' => $environment->redirectUri(),
            'urlAuthorize' => $environment->idpBaseUrl() . '/oauth/authorize',
            'urlAccessToken' => $environment->idpBaseUrl() . '/oauth/token',
            'urlResourceOwnerDetails' => $environment->idpBaseUrl() . '/resources/userinfo',
            'scopes' => $environment->scopes(),
        ]);
    }
}
