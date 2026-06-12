<?php

declare(strict_types=1);

namespace Amtgard\IdpClient\Slim;

use Amtgard\IdpClient\Client\IdpClient;
use Amtgard\IdpClient\Exception\IdpClientException;
use Amtgard\IdpClient\Session\SessionAuthStore;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Interfaces\RouteParserInterface;
use Slim\Routing\RouteContext;

/**
 * Drop-in Slim handlers for /login, /oauth/callback, and /logout.
 */
final class IdpAuthController
{
    public function __construct(
        private readonly IdpClient $idpClient,
        private readonly SessionAuthStore $authStore,
        private readonly string $postLoginRoute = 'home',
        private readonly string $postLogoutRoute = 'home',
        private readonly ?RouteParserInterface $routeParser = null,
    ) {}

    public function login(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $returnTo = $request->getQueryParams()['return_to'] ?? null;

        return $this->idpClient->beginAuthorization(
            is_string($returnTo) && $returnTo !== '' ? $returnTo : null,
        );
    }

    public function callback(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        try {
            $session = $this->idpClient->completeLogin($request);
        } catch (IdpClientException $exception) {
            $response->getBody()->write(sprintf(
                'Login failed [%s]: %s',
                $exception->errorCode()->value,
                $exception->getMessage(),
            ));

            return $response->withStatus(400);
        }

        $this->authStore->store($session);

        $redirect = $session->returnTo ?? $this->routeUrl($request, $this->postLoginRoute);

        return $response
            ->withHeader('Location', $redirect)
            ->withStatus(302);
    }

    public function logout(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $this->authStore->clear();

        return $response
            ->withHeader('Location', $this->routeUrl($request, $this->postLogoutRoute))
            ->withStatus(302);
    }

    private function routeUrl(ServerRequestInterface $request, string $routeName): string
    {
        $parser = $this->routeParser ?? RouteContext::fromRequest($request)->getRouteParser();

        return $parser->urlFor($routeName);
    }
}
