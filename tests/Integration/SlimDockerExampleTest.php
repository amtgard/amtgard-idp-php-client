<?php

declare(strict_types=1);

namespace Amtgard\IdpClient\Tests\Integration;

use GuzzleHttp\Client as GuzzleClient;
use GuzzleHttp\Cookie\CookieJar;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * HTTP integration tests against examples/slim-docker.
 *
 * Start the example app first:
 *   docker compose -f examples/slim-docker/docker-compose.yml up --build -d
 *
 * Run:
 *   SLIM_INTEGRATION=1 composer test -- --testsuite Integration --filter SlimDocker
 */
final class SlimDockerExampleTest extends TestCase
{
    private const DEFAULT_IDP_BASE_URL = 'https://idp.amtgard.com';

    private GuzzleClient $http;
    private string $baseUrl;

    protected function setUp(): void
    {
        if (!filter_var(getenv('SLIM_INTEGRATION') ?: '', FILTER_VALIDATE_BOOL)) {
            $this->markTestSkipped('Set SLIM_INTEGRATION=1 and start examples/slim-docker via docker compose.');
        }

        $this->baseUrl = rtrim(getenv('SLIM_EXAMPLE_URL') ?: 'http://localhost:38080', '/');
        $this->http = new GuzzleClient([
            'base_uri' => $this->baseUrl,
            'http_errors' => false,
            'timeout' => 10,
        ]);

        if (!$this->isReachable()) {
            $this->markTestSkipped(
                'Slim example is not reachable at ' . $this->baseUrl
                . '. Run: docker compose -f examples/slim-docker/docker-compose.yml up --build -d',
            );
        }
    }

    public function testHealthEndpoint(): void
    {
        $response = $this->http->get('/health');
        $body = json_decode((string) $response->getBody(), true, 512, JSON_THROW_ON_ERROR);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('ok', $body['status']);
        $this->assertSame('amtgard-idp-slim-example', $body['service']);
    }

    public function testHomeListsAllLibraryEndpoints(): void
    {
        $response = $this->http->get('/');
        $body = json_decode((string) $response->getBody(), true, 512, JSON_THROW_ON_ERROR);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertArrayHasKey('library_coverage', $body);

        $expected = [
            'beginAuthorization',
            'completeLogin',
            'fetchUserProfile',
            'validate',
            'fetchJwt',
            'refresh',
            'checkAuthorization',
            'sessionProfile',
        ];

        foreach ($expected as $method) {
            $this->assertArrayHasKey($method, $body['library_coverage'], "Missing library_coverage entry for {$method}");
        }
    }

    public function testHomeShowsUnauthenticatedByDefault(): void
    {
        $jar = new CookieJar();
        $response = $this->http->get('/', ['cookies' => $jar]);
        $body = json_decode((string) $response->getBody(), true, 512, JSON_THROW_ON_ERROR);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertFalse($body['authenticated']);
        $this->assertSame('/login', $body['login_url']);
    }

    public function testMeRequiresAuthentication(): void
    {
        $response = $this->http->get('/me', ['cookies' => new CookieJar()]);

        $this->assertSame(401, $response->getStatusCode());
    }

    #[DataProvider('authenticatedResourceRouteProvider')]
    public function testAuthenticatedResourceRoutesRequireLogin(string $method, string $path): void
    {
        $response = $this->http->request($method, $path, ['cookies' => new CookieJar()]);

        $this->assertSame(401, $response->getStatusCode(), "{$method} {$path} should require login");
    }

    /**
     * @return list<array{0: string, 1: string}>
     */
    public static function authenticatedResourceRouteProvider(): array
    {
        return [
            ['GET', '/resources/userinfo'],
            ['GET', '/resources/validate'],
            ['GET', '/resources/jwt'],
            ['POST', '/refresh'],
        ];
    }

    public function testCheckAuthorizationWithEmptyPolicy(): void
    {
        if (!$this->isIdpReachable()) {
            $this->markTestSkipped('IDP is not reachable at ' . $this->idpBaseUrl());
        }

        $response = $this->http->post('/api/check-authorization', [
            'json' => [
                'policy' => [],
                'requirement' => 'Idp:0:0:0:0:IDP/EditClient',
            ],
        ]);
        $body = json_decode((string) $response->getBody(), true, 512, JSON_THROW_ON_ERROR);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertFalse($body['is_authorized']);
    }

    public function testLoginRedirectsToIdpAuthorizeWithPkce(): void
    {
        $jar = new CookieJar();
        $response = $this->http->get('/login', [
            'cookies' => $jar,
            'allow_redirects' => false,
        ]);

        $this->assertSame(302, $response->getStatusCode());

        $location = $response->getHeaderLine('Location');
        $this->assertStringContainsString('/oauth/authorize', $location);
        $this->assertStringContainsString(parse_url($this->idpBaseUrl(), PHP_URL_HOST) ?: 'idp.amtgard.com', $location);

        parse_str((string) parse_url($location, PHP_URL_QUERY), $query);
        $this->assertSame('code', $query['response_type'] ?? null);
        $this->assertSame('S256', $query['code_challenge_method'] ?? null);
        $this->assertNotEmpty($query['code_challenge'] ?? null);
        $this->assertNotEmpty($query['state'] ?? null);
    }

    public function testOAuthCallbackWithInvalidCodeReturnsClientError(): void
    {
        if (!$this->isIdpReachable()) {
            $this->markTestSkipped('IDP is not reachable at ' . $this->idpBaseUrl());
        }

        $jar = new CookieJar();
        $login = $this->http->get('/login', [
            'cookies' => $jar,
            'allow_redirects' => false,
        ]);
        $this->assertSame(302, $login->getStatusCode());

        parse_str((string) parse_url($login->getHeaderLine('Location'), PHP_URL_QUERY), $query);
        $state = $query['state'] ?? '';
        $this->assertNotSame('', $state);

        $callback = $this->http->get('/oauth/callback', [
            'cookies' => $jar,
            'query' => [
                'code' => 'invalid-code-for-slim-integration-test',
                'state' => $state,
            ],
        ]);

        $this->assertSame(400, $callback->getStatusCode());
        $this->assertStringContainsString('IDP_CLIENT_', (string) $callback->getBody());
    }

    private function isReachable(): bool
    {
        try {
            $response = $this->http->get('/health');

            return $response->getStatusCode() === 200;
        } catch (\Throwable) {
            return false;
        }
    }

    private function isIdpReachable(): bool
    {
        try {
            $client = new GuzzleClient(['http_errors' => false, 'timeout' => 10]);
            $response = $client->get($this->idpBaseUrl() . '/oauth/authorize', [
                'allow_redirects' => false,
            ]);

            return $response->getStatusCode() < 500;
        } catch (\Throwable) {
            return false;
        }
    }

    private function idpBaseUrl(): string
    {
        return rtrim(getenv('IDP_BASE_URL') ?: self::DEFAULT_IDP_BASE_URL, '/');
    }
}
