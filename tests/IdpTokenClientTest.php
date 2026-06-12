<?php

declare(strict_types=1);

namespace Amtgard\IdpClient\Tests;

use Amtgard\IdpClient\Exception\ErrorCode;
use Amtgard\IdpClient\Exception\TokenExchangeException;
use Amtgard\IdpClient\OAuth\Http\IdpTokenClient;
use Amtgard\IdpClient\Tests\Support\Fixtures;
use Amtgard\IdpClient\Tests\Support\MockPsr18Client;
use Amtgard\IdpClient\Tests\Support\TestEnvironment;
use Amtgard\IdpClient\Tests\Support\ThrowingHttpClient;
use Nyholm\Psr7\Factory\Psr17Factory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(IdpTokenClient::class)]
final class IdpTokenClientTest extends TestCase
{
    private Psr17Factory $psr17;

    protected function setUp(): void
    {
        $this->psr17 = new Psr17Factory();
    }

    public function testExchangeAuthorizationCodeSendsExpectedHeadersAndFields(): void
    {
        $http = new MockPsr18Client();
        $http->enqueue(
            $this->psr17->createResponse(200)
                ->withHeader('Content-Type', 'application/json')
                ->withBody($this->psr17->createStream(Fixtures::read('token_response.json'))),
        );

        $client = new IdpTokenClient(TestEnvironment::create(), $http, $this->psr17, $this->psr17);
        $tokens = $client->exchangeAuthorizationCode('auth-code', 'verifier-123');

        $request = $http->requests[0];
        $this->assertSame('POST', $request->getMethod());
        $this->assertStringEndsWith('/oauth/token', (string) $request->getUri());
        $this->assertSame('AmtgardIDP/1.0', $request->getHeaderLine('User-Agent'));
        $this->assertSame('application/json', $request->getHeaderLine('Accept'));
        $this->assertSame('application/x-www-form-urlencoded', $request->getHeaderLine('Content-Type'));

        parse_str((string) $request->getBody(), $body);
        $this->assertSame('authorization_code', $body['grant_type']);
        $this->assertSame('auth-code', $body['code']);
        $this->assertSame('verifier-123', $body['code_verifier']);
        $this->assertSame('secret', $body['client_secret']);
        $this->assertSame('test-access-token', $tokens->accessToken());
        $this->assertSame('test-refresh-token', $tokens->refreshToken());
        $this->assertNotNull($tokens->expiresAt());
    }

    public function testExchangeAuthorizationCodeOmitsClientSecretForPublicClient(): void
    {
        $http = new MockPsr18Client();
        $http->enqueue(
            $this->psr17->createResponse(200)
                ->withBody($this->psr17->createStream(Fixtures::read('token_response.json'))),
        );

        $client = new IdpTokenClient(
            TestEnvironment::create(['clientSecret' => null]),
            $http,
            $this->psr17,
            $this->psr17,
        );
        $client->exchangeAuthorizationCode('auth-code', 'verifier-123');

        parse_str((string) $http->requests[0]->getBody(), $body);
        $this->assertArrayNotHasKey('client_secret', $body);
    }

    public function testRefreshSendsRefreshGrantAndMapsTokens(): void
    {
        $http = new MockPsr18Client();
        $http->enqueue(
            $this->psr17->createResponse(200)
                ->withBody($this->psr17->createStream(Fixtures::read('token_response.json'))),
        );

        $client = new IdpTokenClient(TestEnvironment::create(), $http, $this->psr17, $this->psr17);
        $tokens = $client->refresh('old-refresh-token');

        parse_str((string) $http->requests[0]->getBody(), $body);
        $this->assertSame('refresh_token', $body['grant_type']);
        $this->assertSame('old-refresh-token', $body['refresh_token']);
        $this->assertSame('test-access-token', $tokens->accessToken());
    }

    public function testRefreshMapsOAuthErrorToTokenRefreshFailed(): void
    {
        $http = new MockPsr18Client();
        $http->enqueue(
            $this->psr17->createResponse(400)
                ->withBody($this->psr17->createStream(json_encode([
                    'error' => 'invalid_grant',
                    'error_description' => 'Refresh token expired',
                ], JSON_THROW_ON_ERROR))),
        );

        $client = new IdpTokenClient(TestEnvironment::create(), $http, $this->psr17, $this->psr17);

        try {
            $client->refresh('expired-refresh');
            $this->fail('Expected TokenExchangeException');
        } catch (TokenExchangeException $exception) {
            $this->assertSame(ErrorCode::TokenRefreshFailed, $exception->errorCode());
        }
    }

    public function testExchangeAuthorizationCodeMapsWafHtmlToTokenExchangeException(): void
    {
        $http = new MockPsr18Client();
        $http->enqueue(
            $this->psr17->createResponse(403)
                ->withHeader('Content-Type', 'text/html')
                ->withBody($this->psr17->createStream('<html>Cloudflare cf-ray-1</html>')),
        );

        $client = new IdpTokenClient(TestEnvironment::create(), $http, $this->psr17, $this->psr17);

        try {
            $client->exchangeAuthorizationCode('code', 'verifier');
            $this->fail('Expected TokenExchangeException');
        } catch (TokenExchangeException $exception) {
            $this->assertSame(ErrorCode::WafOrHtmlResponse, $exception->errorCode());
        }
    }

    public function testHttpTransportFailureMapsToHttpTransport(): void
    {
        $client = new IdpTokenClient(
            TestEnvironment::create(),
            new ThrowingHttpClient(),
            $this->psr17,
            $this->psr17,
        );

        try {
            $client->exchangeAuthorizationCode('code', 'verifier');
            $this->fail('Expected TokenExchangeException');
        } catch (TokenExchangeException $exception) {
            $this->assertSame(ErrorCode::HttpTransport, $exception->errorCode());
            $this->assertStringContainsString('connection reset', $exception->getMessage());
        }
    }

    public function testEmptyResponseBodyMapsToMalformedJson(): void
    {
        $http = new MockPsr18Client();
        $http->enqueue($this->psr17->createResponse(200)->withBody($this->psr17->createStream('')));

        $client = new IdpTokenClient(TestEnvironment::create(), $http, $this->psr17, $this->psr17);

        try {
            $client->exchangeAuthorizationCode('code', 'verifier');
            $this->fail('Expected TokenExchangeException');
        } catch (TokenExchangeException $exception) {
            $this->assertSame(ErrorCode::MalformedJson, $exception->errorCode());
        }
    }

    public function testInvalidJsonMapsToMalformedJson(): void
    {
        $http = new MockPsr18Client();
        $http->enqueue(
            $this->psr17->createResponse(200)->withBody($this->psr17->createStream('not-json')),
        );

        $client = new IdpTokenClient(TestEnvironment::create(), $http, $this->psr17, $this->psr17);

        try {
            $client->exchangeAuthorizationCode('code', 'verifier');
            $this->fail('Expected TokenExchangeException');
        } catch (TokenExchangeException $exception) {
            $this->assertSame(ErrorCode::MalformedJson, $exception->errorCode());
        }
    }

    public function testNonObjectJsonMapsToMalformedJson(): void
    {
        $http = new MockPsr18Client();
        $http->enqueue(
            $this->psr17->createResponse(200)->withBody($this->psr17->createStream('"just-a-string"')),
        );

        $client = new IdpTokenClient(TestEnvironment::create(), $http, $this->psr17, $this->psr17);

        try {
            $client->exchangeAuthorizationCode('code', 'verifier');
            $this->fail('Expected TokenExchangeException');
        } catch (TokenExchangeException $exception) {
            $this->assertSame(ErrorCode::MalformedJson, $exception->errorCode());
        }
    }

    public function testOAuthErrorPayloadMapsToTokenInvalidClient(): void
    {
        $http = new MockPsr18Client();
        $http->enqueue(
            $this->psr17->createResponse(401)
                ->withBody($this->psr17->createStream(json_encode([
                    'error' => 'invalid_client',
                    'error_description' => 'Client authentication failed',
                ], JSON_THROW_ON_ERROR))),
        );

        $client = new IdpTokenClient(TestEnvironment::create(), $http, $this->psr17, $this->psr17);

        try {
            $client->exchangeAuthorizationCode('code', 'verifier');
            $this->fail('Expected TokenExchangeException');
        } catch (TokenExchangeException $exception) {
            $this->assertSame(ErrorCode::TokenInvalidClient, $exception->errorCode());
        }
    }

    public function testUnexpectedStatusWithoutOAuthErrorMapsToTokenExchangeFailed(): void
    {
        $http = new MockPsr18Client();
        $http->enqueue(
            $this->psr17->createResponse(500)
                ->withBody($this->psr17->createStream('{"ok":false}')),
        );

        $client = new IdpTokenClient(TestEnvironment::create(), $http, $this->psr17, $this->psr17);

        try {
            $client->exchangeAuthorizationCode('code', 'verifier');
            $this->fail('Expected TokenExchangeException');
        } catch (TokenExchangeException $exception) {
            $this->assertSame(ErrorCode::TokenExchangeFailed, $exception->errorCode());
        }
    }

    public function testMissingAccessTokenMapsToMalformedJson(): void
    {
        $http = new MockPsr18Client();
        $http->enqueue(
            $this->psr17->createResponse(200)
                ->withBody($this->psr17->createStream(json_encode(['token_type' => 'Bearer'], JSON_THROW_ON_ERROR))),
        );

        $client = new IdpTokenClient(TestEnvironment::create(), $http, $this->psr17, $this->psr17);

        try {
            $client->exchangeAuthorizationCode('code', 'verifier');
            $this->fail('Expected TokenExchangeException');
        } catch (TokenExchangeException $exception) {
            $this->assertSame(ErrorCode::MalformedJson, $exception->errorCode());
            $this->assertStringContainsString('access_token', $exception->getMessage());
        }
    }

    public function testResponseWithoutRefreshTokenOrExpiryStillReturnsAccessToken(): void
    {
        $http = new MockPsr18Client();
        $http->enqueue(
            $this->psr17->createResponse(200)
                ->withBody($this->psr17->createStream(json_encode([
                    'access_token' => 'bare-access-token',
                    'token_type' => 'Bearer',
                ], JSON_THROW_ON_ERROR))),
        );

        $client = new IdpTokenClient(TestEnvironment::create(), $http, $this->psr17, $this->psr17);
        $tokens = $client->exchangeAuthorizationCode('code', 'verifier');

        $this->assertSame('bare-access-token', $tokens->accessToken());
        $this->assertNull($tokens->refreshToken());
        $this->assertNull($tokens->expiresAt());
    }
}
