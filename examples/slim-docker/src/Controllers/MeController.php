<?php

declare(strict_types=1);

namespace Amtgard\IdpSlimExample\Controllers;

use Amtgard\IdpClient\Session\SessionAuthStore;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

final class MeController
{
    public function __construct(
        private readonly SessionAuthStore $authStore,
    ) {}

    public function me(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        if (!$this->authStore->isAuthenticated()) {
            return $response->withStatus(401);
        }

        $session = $this->authStore->get();
        $profile = $session?->profile;

        $response->getBody()->write(json_encode([
            'id' => $profile?->id,
            'email' => $profile?->email,
            'has_ork_profile' => $profile?->orkProfile !== null,
        ], JSON_THROW_ON_ERROR));

        return $response->withHeader('Content-Type', 'application/json');
    }
}
