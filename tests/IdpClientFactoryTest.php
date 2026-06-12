<?php

declare(strict_types=1);

namespace Amtgard\IdpClient\Tests;

use Amtgard\IdpClient\Client\IdpClient;
use Amtgard\IdpClient\Config\IdpClientEnvironment;
use Amtgard\IdpClient\Config\IdpClientEnvironmentFactory;
use Amtgard\IdpClient\Config\IdpClientFactory;
use Amtgard\IdpClient\OAuth\InMemoryOAuthFlowStateStore;
use Amtgard\IdpClient\OAuth\OAuthFlowState;
use Amtgard\IdpClient\OAuth\Pkce;
use Amtgard\IdpClient\Tests\Support\Fixtures;
use Amtgard\IdpClient\Tests\Support\MockPsr18Client;
use Amtgard\IdpClient\Tests\Support\Psr17HttpClient;
use Amtgard\IdpClient\Tests\Support\TestEnvironment;
use GuzzleHttp\Client as GuzzleClient;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response as GuzzleResponse;
use Nyholm\Psr7\ServerRequest;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(IdpClientFactory::class)]
final class IdpClientFactoryTest extends TestCase
{
    public function testFromEnvironmentBuildsClient(): void
    {
        $client = IdpClientFactory::fromEnvironment(
            TestEnvironment::create(),
            new InMemoryOAuthFlowStateStore(),
        );

        $this->assertInstanceOf(IdpClient::class, $client);
    }

    public function testFromEnvironmentReusesPsr17CapableHttpClient(): void
    {
        $http = new Psr17HttpClient(new MockPsr18Client());
        $client = IdpClientFactory::fromEnvironment(
            TestEnvironment::create(),
            new InMemoryOAuthFlowStateStore(),
            $http,
        );

        $this->assertInstanceOf(IdpClient::class, $client);
    }

    public function testFromEnvironmentDefaultsUserAgentToAmtgardIdpOnTokenExchange(): void
    {
        $container = [];
        $history = Middleware::history($container);
        $handler = HandlerStack::create(new MockHandler([
            new GuzzleResponse(200, ['Content-Type' => 'application/json'], Fixtures::read('token_response.json')),
        ]));
        $handler->push($history);

        $env = IdpClientEnvironmentFactory::fromEnvVars([
            'IDP_BASE_URL' => 'https://idp.test',
            'IDP_CLIENT_ID' => 'app',
            'IDP_REDIRECT_URI' => 'https://app.test/callback',
        ]);
        $guzzle = new GuzzleClient([
            'handler' => $handler,
            'headers' => [
                'User-Agent' => $env->httpUserAgent(),
                'Accept' => 'application/json',
            ],
        ]);

        $flowState = new InMemoryOAuthFlowStateStore();
        $state = Pkce::generateState();
        $flowState->put(new OAuthFlowState($state, Pkce::generateVerifier()));

        $client = IdpClientFactory::fromEnvironment($env, $flowState, $guzzle);

        $this->assertSame(IdpClientEnvironment::DEFAULT_HTTP_USER_AGENT, $env->httpUserAgent());
        $request = (new ServerRequest('GET', '/oauth/callback'))
            ->withQueryParams(['code' => 'auth-code', 'state' => $state]);

        $client->completeAuthorization($request);

        $this->assertSame('AmtgardIDP/1.0', $container[0]['request']->getHeaderLine('User-Agent'));
    }

    public function testFromEnvironmentSendsUserAgentAndAcceptOnTokenExchange(): void
    {
        $container = [];
        $history = Middleware::history($container);
        $handler = HandlerStack::create(new MockHandler([
            new GuzzleResponse(200, ['Content-Type' => 'application/json'], Fixtures::read('token_response.json')),
        ]));
        $handler->push($history);

        $env = TestEnvironment::create(['httpUserAgent' => 'AmtgardClient/1.0 (test-app)']);
        $guzzle = new GuzzleClient([
            'handler' => $handler,
            'headers' => [
                'User-Agent' => $env->httpUserAgent(),
                'Accept' => 'application/json',
            ],
        ]);

        $flowState = new InMemoryOAuthFlowStateStore();
        $state = Pkce::generateState();
        $flowState->put(new OAuthFlowState($state, Pkce::generateVerifier()));

        $client = IdpClientFactory::fromEnvironment($env, $flowState, $guzzle);
        $request = (new ServerRequest('GET', '/oauth/callback'))
            ->withQueryParams(['code' => 'auth-code', 'state' => $state]);

        $client->completeAuthorization($request);

        $this->assertCount(1, $container);
        $tokenRequest = $container[0]['request'];
        $this->assertSame('POST', $tokenRequest->getMethod());
        $this->assertStringEndsWith('/oauth/token', (string) $tokenRequest->getUri());
        $this->assertSame('AmtgardClient/1.0 (test-app)', $tokenRequest->getHeaderLine('User-Agent'));
        $this->assertSame('application/json', $tokenRequest->getHeaderLine('Accept'));
    }
}
