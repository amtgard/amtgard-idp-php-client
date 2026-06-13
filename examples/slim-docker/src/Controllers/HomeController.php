<?php

declare(strict_types=1);

namespace Amtgard\IdpSlimExample\Controllers;

use Amtgard\IdpClient\Session\SessionAuthStore;
use Amtgard\IdpSlimExample\Views\DashboardHtml;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

final class HomeController
{
    public function __construct(
        private readonly SessionAuthStore $authStore,
        private readonly bool $clientIamConfigured,
    ) {}

    public function index(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $payload = $this->buildPayload();

        $response->getBody()->write(DashboardHtml::render(
            authenticated: $payload['authenticated'],
            email: $payload['email'],
            libraryCoverage: $payload['library_coverage'],
            loginUrl: $payload['login_url'],
            logoutUrl: $payload['logout_url'],
            clientIamConfigured: $this->clientIamConfigured,
        ));

        return $response->withHeader('Content-Type', 'text/html; charset=utf-8');
    }

    public function api(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $response->getBody()->write(json_encode($this->buildPayload(), JSON_THROW_ON_ERROR));

        return $response->withHeader('Content-Type', 'application/json');
    }

    /**
     * @return array{
     *     authenticated: bool,
     *     email: string|null,
     *     library_coverage: array<string, array{method: string, path: string}>,
     *     login_url: string,
     *     logout_url: string,
     *     client_iam_configured: bool,
     * }
     */
    private function buildPayload(): array
    {
        $authenticated = $this->authStore->isAuthenticated();
        $email = $authenticated ? $this->authStore->get()?->profile->email : null;

        return [
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
                'clientIamServiceFormat' => ['method' => 'GET', 'path' => '/api/client-iam/service-format'],
                'clientIamComposeClaim' => ['method' => 'POST', 'path' => '/api/client-iam/compose-claim'],
            ],
            'login_url' => '/login',
            'logout_url' => '/logout',
            'client_iam_configured' => $this->clientIamConfigured,
        ];
    }
}
