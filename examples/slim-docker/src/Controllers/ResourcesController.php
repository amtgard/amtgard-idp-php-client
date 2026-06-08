<?php

declare(strict_types=1);

namespace Amtgard\IdpSlimExample\Controllers;

use Amtgard\IdpClient\AuthenticatedSession;
use Amtgard\IdpClient\Exception\IdpClientException;
use Amtgard\IdpClient\IdpClient;
use Amtgard\IdpClient\SessionAuthStore;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Demonstrates every IdpClient resource and token method not covered by IdpAuthController.
 */
final class ResourcesController
{
    public function __construct(
        private readonly IdpClient $idpClient,
        private readonly SessionAuthStore $authStore,
    ) {}

    public function userinfo(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $session = $this->requireAuthenticatedSession($response);
        if ($session instanceof ResponseInterface) {
            return $session;
        }

        return $this->execute($response, fn () => $this->idpClient->fetchUserProfile($session->tokens->accessToken()), static function ($profile) {
            return [
                'id' => $profile->id,
                'email' => $profile->email,
                'jwt' => $profile->jwt,
                'has_ork_profile' => $profile->orkProfile !== null,
            ];
        });
    }

    public function validate(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $session = $this->requireAuthenticatedSession($response);
        if ($session instanceof ResponseInterface) {
            return $session;
        }

        return $this->execute($response, fn () => $this->idpClient->validate($session->tokens->accessToken()), static function ($validated) {
            return [
                'id' => $validated->id,
                'email' => $validated->email,
                'jwt' => $validated->jwt,
            ];
        });
    }

    public function jwt(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $session = $this->requireAuthenticatedSession($response);
        if ($session instanceof ResponseInterface) {
            return $session;
        }

        return $this->execute($response, fn () => $this->idpClient->fetchJwt($session->tokens->accessToken()), static function (string $jwt) {
            return ['jwt' => $jwt];
        });
    }

    public function refresh(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $session = $this->requireAuthenticatedSession($response);
        if ($session instanceof ResponseInterface) {
            return $session;
        }

        return $this->execute($response, function () use ($session) {
            $tokens = $this->idpClient->refresh($session->tokens);
            $profile = $this->idpClient->fetchUserProfile($tokens->accessToken());
            $this->authStore->store(new AuthenticatedSession($tokens, $profile, $session->returnTo));

            return $tokens;
        }, static function ($tokens) {
            return [
                'access_token_prefix' => substr($tokens->accessToken(), 0, 8) . '…',
                'has_refresh_token' => $tokens->refreshToken() !== null && $tokens->refreshToken() !== '',
                'expires_at' => $tokens->expiresAt()?->format(\DateTimeInterface::ATOM),
            ];
        });
    }

    public function checkAuthorization(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $body = $request->getParsedBody();
        if (!is_array($body)) {
            $raw = (string) $request->getBody();
            if ($raw !== '') {
                try {
                    $decoded = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
                    $body = is_array($decoded) ? $decoded : [];
                } catch (\JsonException) {
                    $body = [];
                }
            } else {
                $body = [];
            }
        }

        $policy = $body['policy'] ?? [];
        if (is_string($policy)) {
            try {
                $policy = json_decode($policy, true, 512, JSON_THROW_ON_ERROR);
            } catch (\JsonException) {
                return $this->json($response, [
                    'error' => 'policy must be a JSON array or JSON-encoded string',
                ], 400);
            }
        }

        if (!is_array($policy)) {
            return $this->json($response, [
                'error' => 'policy must be a JSON array',
            ], 400);
        }

        $requirement = $body['requirement'] ?? getenv('EXAMPLE_POLICY_REQUIREMENT') ?: 'Idp:0:0:0:0:IDP/EditClient';
        if (!is_string($requirement) || $requirement === '') {
            return $this->json($response, [
                'error' => 'requirement must be a non-empty string',
            ], 400);
        }

        return $this->execute(
            $response,
            fn () => $this->idpClient->checkAuthorization($policy, $requirement),
            static fn ($check) => ['is_authorized' => $check->isAuthorized],
        );
    }

    /**
     * @param callable(): mixed $action
     * @param callable(mixed): array<string, mixed> $map
     */
    private function execute(ResponseInterface $response, callable $action, callable $map): ResponseInterface
    {
        try {
            return $this->json($response, $map($action()));
        } catch (IdpClientException $exception) {
            return $this->json($response, [
                'error_code' => $exception->errorCode()->value,
                'message' => $exception->getMessage(),
            ], 400);
        }
    }

    private function requireAuthenticatedSession(ResponseInterface $response): AuthenticatedSession|ResponseInterface
    {
        if (!$this->authStore->isAuthenticated()) {
            return $response->withStatus(401);
        }

        $session = $this->authStore->get();
        if ($session === null) {
            return $response->withStatus(401);
        }

        return $session;
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function json(ResponseInterface $response, array $payload, int $status = 200): ResponseInterface
    {
        $response->getBody()->write(json_encode($payload, JSON_THROW_ON_ERROR));

        return $response
            ->withHeader('Content-Type', 'application/json')
            ->withStatus($status);
    }
}
