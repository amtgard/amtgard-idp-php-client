<?php

declare(strict_types=1);

namespace Amtgard\IdpClient\Tests;

use Amtgard\IdpClient\Exception\ErrorCode;
use Amtgard\IdpClient\Exception\InvalidOAuthStateException;
use Amtgard\IdpClient\Exception\TokenExchangeException;
use Amtgard\IdpClient\Client\IdpClient;
use Amtgard\IdpClient\OAuth\InMemoryOAuthFlowStateStore;
use Amtgard\IdpClient\OAuth\OAuthFlowState;
use Amtgard\IdpClient\OAuth\Pkce;
use Amtgard\IdpClient\OAuth\TokenSet;
use Amtgard\IdpClient\Tests\Support\Fixtures;
use Amtgard\IdpClient\Tests\Support\MockPsr18Client;
use Amtgard\IdpClient\Tests\Support\TestEnvironment;
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

        $tokenJson = Fixtures::read('token_response.json');
        $client = $this->createClient([
            new GuzzleResponse(200, ['Content-Type' => 'application/json'], $tokenJson),
            new GuzzleResponse(200, ['Content-Type' => 'application/json'], Fixtures::read('jwt.json')),
            new GuzzleResponse(200, ['Content-Type' => 'application/json'], Fixtures::read('userinfo_without_ork.json')),
        ]);

        $request = (new ServerRequest('GET', '/oauth/callback'))
            ->withQueryParams(['code' => 'auth-code-123', 'state' => $state]);

        $session = $client->completeLogin($request);

        $this->assertSame(42, $session->profile->id);
        $this->assertSame('test-access-token', $session->tokens->accessToken());
    }

    public function testCompleteLoginFallsBackWhenJwtElevationIsRejected(): void
    {
        $state = Pkce::generateState();
        $this->flowState->put(new OAuthFlowState($state, Pkce::generateVerifier()));

        $tokenJson = Fixtures::read('token_response.json');
        $client = $this->createClient([
            new GuzzleResponse(200, ['Content-Type' => 'application/json'], $tokenJson),
            new GuzzleResponse(401),
            new GuzzleResponse(200, ['Content-Type' => 'application/json'], Fixtures::read('userinfo_without_ork.json')),
        ]);

        $request = (new ServerRequest('GET', '/oauth/callback'))
            ->withQueryParams(['code' => 'auth-code-123', 'state' => $state]);

        $session = $client->completeLogin($request);

        $this->assertSame(42, $session->profile->id);
        $this->assertSame('eyJ.noork.jwt', $session->profile->jwt);
    }

    public function testFetchUserProfileForAccessTokenFallsBackWhenJwtElevationIsRejected(): void
    {
        $http = new MockPsr18Client();
        $http->enqueue($this->psr17->createResponse(401));
        $http->enqueue(
            $this->psr17->createResponse(200)->withBody(
                $this->psr17->createStream(Fixtures::read('userinfo_without_ork.json')),
            ),
        );

        $client = $this->createClient(http: $http);
        $profile = $client->fetchUserProfileForAccessToken('oauth-access-token');

        $this->assertSame(42, $profile->id);
        $this->assertStringEndsWith('/resources/userinfo', (string) $http->requests[1]->getUri());
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

    public function testCompleteAuthorizationMapsWafHtmlResponse(): void
    {
        $state = Pkce::generateState();
        $this->flowState->put(new OAuthFlowState($state, Pkce::generateVerifier()));

        $html = '<!DOCTYPE html><html><title>Just a moment...</title>cf-ray-abc123</html>';
        $client = $this->createClient([
            new GuzzleResponse(403, ['Content-Type' => 'text/html; charset=UTF-8'], $html),
        ]);

        $request = (new ServerRequest('GET', '/oauth/callback'))
            ->withQueryParams(['code' => 'code', 'state' => $state]);

        try {
            $client->completeAuthorization($request);
            $this->fail('Expected TokenExchangeException');
        } catch (TokenExchangeException $exception) {
            $this->assertSame(ErrorCode::WafOrHtmlResponse, $exception->errorCode());
            $this->assertStringContainsString('README', $exception->getMessage());
            $this->assertStringContainsString('HTTP 403', $exception->getMessage());
        }
    }

    public function testCompleteLoginMapsWafHtmlResponse(): void
    {
        $state = Pkce::generateState();
        $this->flowState->put(new OAuthFlowState($state, Pkce::generateVerifier()));

        $html = '<html>Cloudflare Attention Required cf-ray-xyz</html>';
        $client = $this->createClient([
            new GuzzleResponse(403, ['Content-Type' => 'text/html'], $html),
        ]);

        $request = (new ServerRequest('GET', '/oauth/callback'))
            ->withQueryParams(['code' => 'code', 'state' => $state]);

        try {
            $client->completeLogin($request);
            $this->fail('Expected TokenExchangeException');
        } catch (TokenExchangeException $exception) {
            $this->assertSame(ErrorCode::WafOrHtmlResponse, $exception->errorCode());
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

    public function testFetchJwtForAccessTokenReturnsJwsAccessTokenWithoutHttpCall(): void
    {
        $http = new MockPsr18Client();
        $client = $this->createClient(http: $http);

        $jwt = $client->fetchJwtForAccessToken('header.payload.sig');

        $this->assertSame('header.payload.sig', $jwt);
        $this->assertCount(0, $http->requests);
    }

    public function testValidateForAccessTokenUsesSameBearerOnUserinfoAndValidate(): void
    {
        $http = new MockPsr18Client();
        $http->enqueue(
            $this->psr17->createResponse(200)
                ->withHeader('Set-Cookie', 'PHPSESSID=abc; Path=/')
                ->withBody($this->psr17->createStream(Fixtures::read('userinfo_without_ork.json'))),
        );
        $http->enqueue(
            $this->psr17->createResponse(200)->withBody(
                $this->psr17->createStream(Fixtures::read('validate.json')),
            ),
        );

        $client = $this->createClient(http: $http);
        $session = $client->validateForAccessToken('header.one.sig');

        $this->assertStringEndsWith('/resources/userinfo', (string) $http->requests[0]->getUri());
        $this->assertSame('Bearer header.one.sig', $http->requests[0]->getHeaderLine('Authorization'));
        $this->assertStringEndsWith('/resources/validate', (string) $http->requests[1]->getUri());
        $this->assertSame('Bearer header.one.sig', $http->requests[1]->getHeaderLine('Authorization'));
        $this->assertSame(42, $session->id);
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
     * @param list<GuzzleResponse> $responses
     */
    private function createClient(array $responses = [], ?MockPsr18Client $http = null): IdpClient
    {
        $env = TestEnvironment::create();
        $http ??= new MockPsr18Client();

        foreach ($responses as $response) {
            $http->enqueue($this->toPsrResponse($response));
        }

        return new IdpClient($env, $this->flowState, $http, $this->psr17, $this->psr17);
    }

    private function toPsrResponse(GuzzleResponse $response): \Psr\Http\Message\ResponseInterface
    {
        $psrResponse = $this->psr17->createResponse($response->getStatusCode());

        foreach ($response->getHeaders() as $name => $values) {
            foreach ($values as $value) {
                $psrResponse = $psrResponse->withHeader($name, $value);
            }
        }

        return $psrResponse->withBody(
            $this->psr17->createStream((string) $response->getBody()),
        );
    }
}
