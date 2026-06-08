<?php

declare(strict_types=1);

namespace Amtgard\IdpClient\Tests;

use Amtgard\IdpClient\Exception\ErrorCode;
use Amtgard\IdpClient\Exception\InvalidOAuthStateException;
use Amtgard\IdpClient\Exception\TokenExchangeException;
use Amtgard\IdpClient\IdpClient;
use Amtgard\IdpClient\IdpProvider;
use Amtgard\IdpClient\InMemoryOAuthFlowStateStore;
use Amtgard\IdpClient\OAuthFlowState;
use Amtgard\IdpClient\Pkce;
use Amtgard\IdpClient\TokenSet;
use Amtgard\IdpClient\Tests\Support\Fixtures;
use Amtgard\IdpClient\Tests\Support\MockPsr18Client;
use Amtgard\IdpClient\Tests\Support\TestEnvironment;
use GuzzleHttp\Client as GuzzleClient;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response as GuzzleResponse;
use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\ServerRequest;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(IdpClient::class)]
final class IdpClientTest extends TestCase
{
    private Psr17Factory $psr17;
    private InMemoryOAuthFlowStateStore $flowState;

    protected function setUp(): void
    {
        $this->psr17 = new Psr17Factory();
        $this->flowState = new InMemoryOAuthFlowStateStore();
    }

    public function testBeginAuthorizationRedirectsWithPkceAndState(): void
    {
        $client = $this->createClient();

        $response = $client->beginAuthorization('/dashboard');

        $this->assertSame(302, $response->getStatusCode());
        $location = $response->getHeaderLine('Location');
        $this->assertStringStartsWith('https://idp.test/oauth/authorize?', $location);

        parse_str((string) parse_url($location, PHP_URL_QUERY), $query);
        $this->assertSame('code', $query['response_type'] ?? null);
        $this->assertSame('test-client', $query['client_id'] ?? null);
        $this->assertSame('S256', $query['code_challenge_method'] ?? null);
        $this->assertNotEmpty($query['code_challenge'] ?? null);
        $this->assertNotEmpty($query['state'] ?? null);

        $stored = $this->flowState->pull();
        $this->assertNotNull($stored);
        $this->assertSame($query['state'], $stored->state);
        $this->assertSame(
            $query['code_challenge'],
            Pkce::challengeFromVerifier($stored->codeVerifier),
        );
        $this->assertSame('/dashboard', $stored->returnTo);
    }

    public function testCompleteAuthorizationExchangesCodeForTokens(): void
    {
        $verifier = Pkce::generateVerifier();
        $state = Pkce::generateState();
        $this->flowState->put(new OAuthFlowState($state, $verifier));

        $tokenJson = Fixtures::read('token_response.json');
        $client = $this->createClient([
            new GuzzleResponse(200, ['Content-Type' => 'application/json'], $tokenJson),
        ]);

        $request = (new ServerRequest('GET', '/oauth/callback'))
            ->withQueryParams(['code' => 'auth-code-123', 'state' => $state]);

        $result = $client->completeAuthorization($request);

        $this->assertSame('test-access-token', $result->tokens->accessToken());
        $this->assertSame('test-refresh-token', $result->tokens->refreshToken());
        $this->assertNotNull($result->tokens->expiresAt());
    }

    public function testCompleteAuthorizationPreservesReturnTo(): void
    {
        $state = Pkce::generateState();
        $this->flowState->put(new OAuthFlowState($state, Pkce::generateVerifier(), '/dashboard'));

        $tokenJson = Fixtures::read('token_response.json');
        $client = $this->createClient([
            new GuzzleResponse(200, ['Content-Type' => 'application/json'], $tokenJson),
        ]);

        $request = (new ServerRequest('GET', '/oauth/callback'))
            ->withQueryParams(['code' => 'auth-code-123', 'state' => $state]);

        $result = $client->completeAuthorization($request);

        $this->assertSame('/dashboard', $result->returnTo);
    }

    public function testCompleteLoginFetchesProfile(): void
    {
        $state = Pkce::generateState();
        $this->flowState->put(new OAuthFlowState($state, Pkce::generateVerifier()));

        $http = new MockPsr18Client();
        $http->enqueue(
            $this->psr17->createResponse(200)->withBody(
                $this->psr17->createStream(Fixtures::read('userinfo_without_ork.json')),
            ),
        );

        $tokenJson = Fixtures::read('token_response.json');
        $client = $this->createClient([
            new GuzzleResponse(200, ['Content-Type' => 'application/json'], $tokenJson),
        ], $http);

        $request = (new ServerRequest('GET', '/oauth/callback'))
            ->withQueryParams(['code' => 'auth-code-123', 'state' => $state]);

        $session = $client->completeLogin($request);

        $this->assertSame(42, $session->profile->id);
        $this->assertSame('test-access-token', $session->tokens->accessToken());
    }

    public function testCompleteAuthorizationRejectsMissingStateParam(): void
    {
        $client = $this->createClient();
        $request = (new ServerRequest('GET', '/oauth/callback'))
            ->withQueryParams(['code' => 'code']);

        try {
            $client->completeAuthorization($request);
            $this->fail('Expected InvalidOAuthStateException');
        } catch (InvalidOAuthStateException $exception) {
            $this->assertSame(ErrorCode::StateParamMissing, $exception->errorCode());
        }
    }

    public function testCompleteAuthorizationRejectsMissingAuthCode(): void
    {
        $this->flowState->put(new OAuthFlowState('state', Pkce::generateVerifier()));
        $client = $this->createClient();
        $request = (new ServerRequest('GET', '/oauth/callback'))
            ->withQueryParams(['state' => 'state']);

        try {
            $client->completeAuthorization($request);
            $this->fail('Expected InvalidOAuthStateException');
        } catch (InvalidOAuthStateException $exception) {
            $this->assertSame(ErrorCode::AuthCodeMissing, $exception->errorCode());
        }
    }

    public function testCompleteAuthorizationRejectsStateMismatch(): void
    {
        $this->flowState->put(new OAuthFlowState('expected', Pkce::generateVerifier()));
        $client = $this->createClient();

        $request = (new ServerRequest('GET', '/oauth/callback'))
            ->withQueryParams(['code' => 'code', 'state' => 'wrong']);

        try {
            $client->completeAuthorization($request);
            $this->fail('Expected InvalidOAuthStateException');
        } catch (InvalidOAuthStateException $exception) {
            $this->assertSame(ErrorCode::StateMismatch, $exception->errorCode());
        }
    }

    public function testCompleteAuthorizationRejectsMissingFlowState(): void
    {
        $client = $this->createClient();
        $request = (new ServerRequest('GET', '/oauth/callback'))
            ->withQueryParams(['code' => 'code', 'state' => 'state']);

        try {
            $client->completeAuthorization($request);
            $this->fail('Expected InvalidOAuthStateException');
        } catch (InvalidOAuthStateException $exception) {
            $this->assertSame(ErrorCode::FlowStateMissing, $exception->errorCode());
        }
    }

    public function testCompleteAuthorizationRejectsNonStringOAuthErrorDescription(): void
    {
        $client = $this->createClient();
        $request = (new ServerRequest('GET', '/oauth/callback'))
            ->withQueryParams([
                'error' => 'access_denied',
                'error_description' => ['nested' => 'ignored'],
            ]);

        try {
            $client->completeAuthorization($request);
            $this->fail('Expected InvalidOAuthStateException');
        } catch (InvalidOAuthStateException $exception) {
            $this->assertSame(ErrorCode::OAuthCallbackError, $exception->errorCode());
            $this->assertNull($exception->idpErrorDescription());
        }
    }

    public function testCompleteAuthorizationRejectsOAuthCallbackError(): void
    {
        $client = $this->createClient();
        $request = (new ServerRequest('GET', '/oauth/callback'))
            ->withQueryParams([
                'error' => 'access_denied',
                'error_description' => 'User denied consent',
            ]);

        try {
            $client->completeAuthorization($request);
            $this->fail('Expected InvalidOAuthStateException');
        } catch (InvalidOAuthStateException $exception) {
            $this->assertSame(ErrorCode::OAuthCallbackError, $exception->errorCode());
            $this->assertSame('access_denied', $exception->idpError());
        }
    }

    public function testCompleteAuthorizationMapsTokenExchangeFailure(): void
    {
        $verifier = Pkce::generateVerifier();
        $state = Pkce::generateState();
        $this->flowState->put(new OAuthFlowState($state, $verifier));

        $errorBody = json_encode([
            'error' => 'invalid_grant',
            'error_description' => 'code_verifier mismatch',
        ], JSON_THROW_ON_ERROR);

        $client = $this->createClient([
            new GuzzleResponse(400, ['Content-Type' => 'application/json'], $errorBody),
        ]);

        $request = (new ServerRequest('GET', '/oauth/callback'))
            ->withQueryParams(['code' => 'code', 'state' => $state]);

        try {
            $client->completeAuthorization($request);
            $this->fail('Expected TokenExchangeException');
        } catch (TokenExchangeException $exception) {
            $this->assertSame(ErrorCode::TokenPkceFailed, $exception->errorCode());
        }
    }

    public function testRefreshReturnsNewTokenSet(): void
    {
        $tokenJson = Fixtures::read('token_response.json');
        $client = $this->createClient([
            new GuzzleResponse(200, ['Content-Type' => 'application/json'], $tokenJson),
        ]);

        $refreshed = $client->refresh(new TokenSet('old-access', 'old-refresh'));

        $this->assertSame('test-access-token', $refreshed->accessToken());
    }

    public function testRefreshWithoutRefreshTokenFails(): void
    {
        $client = $this->createClient();

        $this->expectException(TokenExchangeException::class);
        $client->refresh(new TokenSet('access-only'));
    }

    public function testValidateDelegatesToResourceClient(): void
    {
        $http = new MockPsr18Client();
        $http->enqueue(
            $this->psr17->createResponse(200)->withBody(
                $this->psr17->createStream('{"id":1,"email":"a@b.com","jwt":"jwt"}'),
            ),
        );

        $client = $this->createClient(http: $http);
        $session = $client->validate('access-token');

        $this->assertStringEndsWith('/resources/validate', (string) $http->requests[0]->getUri());
        $this->assertSame(1, $session->id);
        $this->assertSame('jwt', $session->jwt);
    }

    public function testRefreshMapsFailure(): void
    {
        $errorBody = json_encode(['error' => 'invalid_grant', 'error_description' => 'expired'], JSON_THROW_ON_ERROR);
        $client = $this->createClient([
            new GuzzleResponse(400, ['Content-Type' => 'application/json'], $errorBody),
        ]);

        try {
            $client->refresh(new TokenSet('access', 'refresh'));
            $this->fail('Expected TokenExchangeException');
        } catch (TokenExchangeException $exception) {
            $this->assertSame(ErrorCode::TokenRefreshFailed, $exception->errorCode());
        }
    }

    public function testFetchUserProfileUsesResourceClient(): void
    {
        $http = new MockPsr18Client();
        $json = Fixtures::read('userinfo_with_ork.json');
        $http->enqueue($this->psr17->createResponse(200)->withBody($this->psr17->createStream($json)));

        $client = $this->createClient(http: $http);
        $profile = $client->fetchUserProfile('access');

        $this->assertSame('player@amtgard.com', $profile->email);
    }

    /**
     * @param list<GuzzleResponse> $guzzleResponses
     */
    private function createClient(array $guzzleResponses = [], ?MockPsr18Client $http = null): IdpClient
    {
        $env = TestEnvironment::create();
        $http ??= new MockPsr18Client();

        $handler = HandlerStack::create(new MockHandler($guzzleResponses));
        $guzzle = new GuzzleClient(['handler' => $handler]);

        $provider = new IdpProvider(
            [
                'clientId' => $env->clientId(),
                'clientSecret' => $env->clientSecret() ?? '',
                'redirectUri' => $env->redirectUri(),
                'urlAuthorize' => $env->idpBaseUrl() . '/oauth/authorize',
                'urlAccessToken' => $env->idpBaseUrl() . '/oauth/token',
                'urlResourceOwnerDetails' => $env->idpBaseUrl() . '/resources/userinfo',
                'scopes' => $env->scopes(),
            ],
            ['httpClient' => $guzzle],
        );

        return new IdpClient(
            $env,
            $this->flowState,
            $http,
            $this->psr17,
            $this->psr17,
            $provider,
        );
    }
}
