<?php

declare(strict_types=1);

namespace Amtgard\IdpClient\Tests\Slim;

use Amtgard\IdpClient\Client\IdpClient;
use Amtgard\IdpClient\OAuth\InMemoryOAuthFlowStateStore;
use Amtgard\IdpClient\OAuth\OAuthFlowState;
use Amtgard\IdpClient\OAuth\Pkce;
use Amtgard\IdpClient\Session\SessionAuthStore;
use Amtgard\IdpClient\Slim\IdpAuthController;
use Amtgard\IdpClient\Tests\Support\Fixtures;
use Amtgard\IdpClient\Tests\Support\MockPsr18Client;
use Amtgard\IdpClient\Tests\Support\TestEnvironment;
use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\ServerRequest;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Slim\Factory\AppFactory;

#[CoversClass(IdpAuthController::class)]
final class IdpAuthControllerTest extends TestCase
{
    private Psr17Factory $psr17;

    protected function setUp(): void
    {
        @session_start();
        $_SESSION = [];
        $this->psr17 = new Psr17Factory();
    }

    public function testLoginRedirectsToIdpAuthorize(): void
    {
        $controller = new IdpAuthController(
            $this->createIdpClient(new InMemoryOAuthFlowStateStore(), new MockPsr18Client()),
            new SessionAuthStore(),
        );

        $response = $controller->login(
            (new ServerRequest('GET', '/login'))->withQueryParams(['return_to' => '/reports']),
            $this->psr17->createResponse(),
        );

        $this->assertSame(302, $response->getStatusCode());
        $this->assertStringContainsString('/oauth/authorize', $response->getHeaderLine('Location'));
    }

    public function testCallbackReturns400OnIdpClientException(): void
    {
        $controller = new IdpAuthController(
            $this->createIdpClient(new InMemoryOAuthFlowStateStore(), new MockPsr18Client()),
            new SessionAuthStore(),
            routeParser: $this->routeParser(),
        );

        $response = $controller->callback(
            (new ServerRequest('GET', '/oauth/callback'))->withQueryParams(['code' => 'x']),
            $this->psr17->createResponse(),
        );

        $this->assertSame(400, $response->getStatusCode());
    }

    public function testCallbackStoresSessionAndRedirectsToReturnTo(): void
    {
        $flowState = new InMemoryOAuthFlowStateStore();
        $state = Pkce::generateState();
        $flowState->put(new OAuthFlowState($state, Pkce::generateVerifier(), '/after-login'));

        $http = new MockPsr18Client();
        $idpClient = $this->createIdpClient($flowState, $http);
        $http->enqueue(
            $this->psr17->createResponse(200)->withBody(
                $this->psr17->createStream(Fixtures::read('jwt.json')),
            ),
        );
        $http->enqueue(
            $this->psr17->createResponse(200)->withBody(
                $this->psr17->createStream(Fixtures::read('userinfo_without_ork.json')),
            ),
        );
        $authStore = new SessionAuthStore();
        $controller = new IdpAuthController(
            $idpClient,
            $authStore,
            'home',
            'home',
            $this->routeParser(),
        );

        $request = (new ServerRequest('GET', '/oauth/callback'))
            ->withQueryParams(['code' => 'auth-code', 'state' => $state]);

        $response = $controller->callback($request, $this->psr17->createResponse());

        $this->assertSame(302, $response->getStatusCode());
        $this->assertSame('/after-login', $response->getHeaderLine('Location'));
        $this->assertTrue($authStore->isAuthenticated());
    }

    public function testLogoutClearsSessionAndRedirectsHome(): void
    {
        $authStore = new SessionAuthStore();
        $authStore->store(new \Amtgard\IdpClient\Resource\AuthenticatedSession(
            new \Amtgard\IdpClient\OAuth\TokenSet('access'),
            \Amtgard\IdpClient\Resource\UserProfile::fromArray(['id' => 1, 'email' => 'a@b.com', 'jwt' => 'jwt']),
        ));

        $controller = new IdpAuthController(
            $this->createIdpClient(new InMemoryOAuthFlowStateStore(), new MockPsr18Client()),
            $authStore,
            'home',
            'home',
            $this->routeParser(),
        );

        $response = $controller->logout(new ServerRequest('GET', '/logout'), $this->psr17->createResponse());

        $this->assertSame(302, $response->getStatusCode());
        $this->assertSame('/home', $response->getHeaderLine('Location'));
        $this->assertFalse($authStore->isAuthenticated());
    }

    private function routeParser(): \Slim\Interfaces\RouteParserInterface
    {
        $app = AppFactory::create();
        $app->get('/home', fn () => $this->psr17->createResponse())->setName('home');

        return $app->getRouteCollector()->getRouteParser();
    }

    private function createIdpClient(InMemoryOAuthFlowStateStore $flowState, MockPsr18Client $http): IdpClient
    {
        $http->enqueue(
            $this->psr17->createResponse(200)
                ->withHeader('Content-Type', 'application/json')
                ->withBody($this->psr17->createStream(Fixtures::read('token_response.json'))),
        );

        return new IdpClient(
            TestEnvironment::create(),
            $flowState,
            $http,
            $this->psr17,
            $this->psr17,
        );
    }
}
