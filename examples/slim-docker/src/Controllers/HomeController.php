<?php

declare(strict_types=1);

namespace Amtgard\IdpSlimExample\Controllers;

use Amtgard\IdpClient\Session\SessionAuthStore;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

final class HomeController
{
    public function __construct(
        private readonly SessionAuthStore $authStore,
    ) {}

    public function index(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $authenticated = $this->authStore->isAuthenticated();
        $email = $authenticated ? $this->authStore->get()?->profile->email : null;

        $response->getBody()->write(json_encode([
            'authenticated' => $authenticated,
            'email' => $email,
            'library_coverage' => [
                'beginAuthorization' => ['method' => 'GET', 'path' => '/login'],
                'completeLogin' => ['method' => 'GET', 'path' => '/oauth/callback'],
                'fetchUserProfile' => ['method' => 'GET', 'path' => '/resources/userinfo'],
                'validate' => ['method' => 'GET', 'path' => '/resources/validate'],
                'fetchJwt' => ['method' => 'GET', 'path' => '/resources/jwt'],
                'refresh' => ['method' => 'POST', 'path' => '/refresh'],
                'checkAuthorization' => ['method' => 'POST', 'path' => '/api/check-authorization'],
                'sessionProfile' => ['method' => 'GET', 'path' => '/me'],
            ],
            'login_url' => '/login',
            'logout_url' => '/logout',
        ], JSON_THROW_ON_ERROR));

        return $response->withHeader('Content-Type', 'application/json');
    }
}
