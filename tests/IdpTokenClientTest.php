<?php

declare(strict_types=1);

namespace Amtgard\IdpClient\Tests;

use Amtgard\IdpClient\Exception\ErrorCode;
use Amtgard\IdpClient\Exception\TokenExchangeException;
use Amtgard\IdpClient\Http\IdpTokenClient;
use Amtgard\IdpClient\Tests\Support\Fixtures;
use Amtgard\IdpClient\Tests\Support\MockPsr18Client;
use Amtgard\IdpClient\Tests\Support\TestEnvironment;
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
        $this->assertSame('test-access-token', $tokens->accessToken());
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
}
