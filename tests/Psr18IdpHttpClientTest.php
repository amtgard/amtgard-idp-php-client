<?php

declare(strict_types=1);

namespace Amtgard\IdpClient\Tests;

use Amtgard\IdpClient\Exception\ErrorCode;
use Amtgard\IdpClient\Exception\ResourceException;
use Amtgard\IdpClient\Http\Psr18IdpHttpClient;
use Amtgard\IdpClient\Tests\Support\Fixtures;
use Amtgard\IdpClient\Tests\Support\MockPsr18Client;
use Amtgard\IdpClient\Tests\Support\ThrowingHttpClient;
use Amtgard\IdpClient\Tests\Support\TestEnvironment;
use Nyholm\Psr7\Factory\Psr17Factory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(Psr18IdpHttpClient::class)]
final class Psr18IdpHttpClientTest extends TestCase
{
    private Psr17Factory $psr17;

    protected function setUp(): void
    {
        $this->psr17 = new Psr17Factory();
    }

    public function testFetchUserProfileSendsBearerAndUserAgent(): void
    {
        $http = new MockPsr18Client();
        $json = Fixtures::read('userinfo_without_ork.json');
        $http->enqueue($this->psr17->createResponse(200)->withBody($this->psr17->createStream($json)));

        $client = new Psr18IdpHttpClient(TestEnvironment::create(), $http, $this->psr17, $this->psr17);
        $profile = $client->fetchUserProfile('token-abc');

        $request = $http->requests[0];
        $this->assertSame('Bearer token-abc', $request->getHeaderLine('Authorization'));
        $this->assertSame('AmtgardIDP/1.0', $request->getHeaderLine('User-Agent'));
        $this->assertStringEndsWith('/resources/userinfo', (string) $request->getUri());
        $this->assertSame(42, $profile->id);
    }

    public function testValidateReturnsTypedSession(): void
    {
        $http = new MockPsr18Client();
        $json = Fixtures::read('validate.json');
        $http->enqueue(
            $this->psr17->createResponse(200)->withBody($this->psr17->createStream($json)),
        );

        $client = new Psr18IdpHttpClient(TestEnvironment::create(), $http, $this->psr17, $this->psr17);
        $session = $client->validate('token');

        $this->assertStringEndsWith('/resources/validate', (string) $http->requests[0]->getUri());
        $this->assertSame(42, $session->id);
        $this->assertSame('player@amtgard.com', $session->email);
    }

    public function testFetchJwtReturnsJwtString(): void
    {
        $http = new MockPsr18Client();
        $http->enqueue(
            $this->psr17->createResponse(200)->withBody(
                $this->psr17->createStream(Fixtures::read('jwt.json')),
            ),
        );

        $client = new Psr18IdpHttpClient(TestEnvironment::create(), $http, $this->psr17, $this->psr17);
        $jwt = $client->fetchJwt('token-abc');

        $request = $http->requests[0];
        $this->assertSame('Bearer token-abc', $request->getHeaderLine('Authorization'));
        $this->assertStringEndsWith('/resources/jwt', (string) $request->getUri());
        $this->assertSame('eyJhbGciOiJIUzI1NiJ9.fresh-jwt', $jwt);
    }

    public function testCheckAuthorizationPostsPolicyAndRequirement(): void
    {
        $http = new MockPsr18Client();
        $http->enqueue(
            $this->psr17->createResponse(200)->withBody(
                $this->psr17->createStream(Fixtures::read('is_authorized_true.json')),
            ),
        );

        $client = new Psr18IdpHttpClient(TestEnvironment::create(), $http, $this->psr17, $this->psr17);
        $check = $client->checkAuthorization([], 'Idp:0:0:0:0:IDP/EditClient');

        $request = $http->requests[0];
        $this->assertSame('POST', $request->getMethod());
        $this->assertStringEndsWith('/api/is_authorized', (string) $request->getUri());
        $this->assertSame('application/x-www-form-urlencoded', $request->getHeaderLine('Content-Type'));
        $this->assertStringNotContainsString('Authorization', $request->getHeaderLine('Authorization'));
        parse_str((string) $request->getBody(), $body);
        $this->assertSame('[]', $body['policy']);
        $this->assertSame('Idp:0:0:0:0:IDP/EditClient', $body['requirement']);
        $this->assertTrue($check->isAuthorized);
    }

    public function testUnauthorizedMapsToResourceException(): void
    {
        $http = new MockPsr18Client();
        $http->enqueue($this->psr17->createResponse(401));

        $client = new Psr18IdpHttpClient(TestEnvironment::create(), $http, $this->psr17, $this->psr17);

        try {
            $client->fetchUserProfile('bad-token');
            $this->fail('Expected ResourceException');
        } catch (ResourceException $exception) {
            $this->assertSame(ErrorCode::ResourceUnauthorized, $exception->errorCode());
        }
    }

    public function testWafHtmlMapsToDedicatedErrorCode(): void
    {
        $http = new MockPsr18Client();
        $http->enqueue(
            $this->psr17->createResponse(403)->withBody(
                $this->psr17->createStream('<html>Cloudflare cf-ray-123</html>'),
            ),
        );

        $client = new Psr18IdpHttpClient(TestEnvironment::create(), $http, $this->psr17, $this->psr17);

        try {
            $client->validate('token');
            $this->fail('Expected ResourceException');
        } catch (ResourceException $exception) {
            $this->assertSame(ErrorCode::WafOrHtmlResponse, $exception->errorCode());
        }
    }

    public function testPolicyErrorMapsTo422(): void
    {
        $http = new MockPsr18Client();
        $http->enqueue(
            $this->psr17->createResponse(422)->withBody(
                $this->psr17->createStream('{"error":"Malformed user policy"}'),
            ),
        );

        $client = new Psr18IdpHttpClient(TestEnvironment::create(), $http, $this->psr17, $this->psr17);

        try {
            $client->fetchUserProfile('token');
            $this->fail('Expected ResourceException');
        } catch (ResourceException $exception) {
            $this->assertSame(ErrorCode::ResourcePolicyError, $exception->errorCode());
        }
    }

    public function testUnexpectedStatusMapsCorrectly(): void
    {
        $http = new MockPsr18Client();
        $http->enqueue($this->psr17->createResponse(500)->withBody($this->psr17->createStream('error')));

        $client = new Psr18IdpHttpClient(TestEnvironment::create(), $http, $this->psr17, $this->psr17);

        try {
            $client->validate('token');
            $this->fail('Expected ResourceException');
        } catch (ResourceException $exception) {
            $this->assertSame(ErrorCode::ResourceUnexpectedStatus, $exception->errorCode());
        }
    }

    public function testValidateAcceptsEmptyJsonBodyOnSuccess(): void
    {
        $http = new MockPsr18Client();
        $http->enqueue($this->psr17->createResponse(204));

        $client = new Psr18IdpHttpClient(TestEnvironment::create(), $http, $this->psr17, $this->psr17);
        $session = $client->validate('token');

        $this->assertSame(0, $session->id);
        $this->assertSame('', $session->email);
        $this->assertSame('', $session->jwt);
    }

    public function testFetchJwtRejectsMissingJwtField(): void
    {
        $http = new MockPsr18Client();
        $http->enqueue(
            $this->psr17->createResponse(200)->withBody($this->psr17->createStream('{}')),
        );

        $client = new Psr18IdpHttpClient(TestEnvironment::create(), $http, $this->psr17, $this->psr17);

        try {
            $client->fetchJwt('token');
            $this->fail('Expected ResourceException');
        } catch (ResourceException $exception) {
            $this->assertSame(ErrorCode::MalformedJson, $exception->errorCode());
        }
    }

    public function testHttpTransportFailure(): void
    {
        $client = new Psr18IdpHttpClient(TestEnvironment::create(), new ThrowingHttpClient(), $this->psr17, $this->psr17);

        try {
            $client->fetchUserProfile('token');
            $this->fail('Expected ResourceException');
        } catch (ResourceException $exception) {
            $this->assertSame(ErrorCode::HttpTransport, $exception->errorCode());
        }
    }

    public function testEmptyBodyOnErrorStatusThrows(): void
    {
        $http = new MockPsr18Client();
        $http->enqueue($this->psr17->createResponse(500));

        $client = new Psr18IdpHttpClient(TestEnvironment::create(), $http, $this->psr17, $this->psr17);

        try {
            $client->fetchUserProfile('token');
            $this->fail('Expected ResourceException');
        } catch (ResourceException $exception) {
            $this->assertSame(ErrorCode::ResourceUnexpectedStatus, $exception->errorCode());
        }
    }

    public function testNonObjectJsonThrowsMalformedError(): void
    {
        $http = new MockPsr18Client();
        $http->enqueue($this->psr17->createResponse(200)->withBody($this->psr17->createStream('"not-an-object"')));

        $client = new Psr18IdpHttpClient(TestEnvironment::create(), $http, $this->psr17, $this->psr17);

        try {
            $client->fetchUserProfile('token');
            $this->fail('Expected ResourceException');
        } catch (ResourceException $exception) {
            $this->assertSame(ErrorCode::MalformedJson, $exception->errorCode());
        }
    }

    public function testMalformedJsonThrows(): void
    {
        $http = new MockPsr18Client();
        $http->enqueue($this->psr17->createResponse(200)->withBody($this->psr17->createStream('not-json')));

        $client = new Psr18IdpHttpClient(TestEnvironment::create(), $http, $this->psr17, $this->psr17);

        $this->expectException(ResourceException::class);
        $this->expectExceptionCode(0);
        $client->fetchUserProfile('token');
    }
}
