<?php

declare(strict_types=1);

namespace Amtgard\IdpClient\Tests\ClientIam\Http;

use Amtgard\IdpClient\ClientIam\Http\Psr18ClientIamHttpClient;
use Amtgard\IdpClient\Exception\ClientIamException;
use Amtgard\IdpClient\Exception\ErrorCode;
use Amtgard\IdpClient\Tests\Support\Fixtures;
use Amtgard\IdpClient\Tests\Support\MockPsr18Client;
use Amtgard\IdpClient\Tests\Support\ThrowingHttpClient;
use Amtgard\IdpClient\Tests\Support\TestEnvironment;
use Nyholm\Psr7\Factory\Psr17Factory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(Psr18ClientIamHttpClient::class)]
final class Psr18ClientIamHttpClientTest extends TestCase
{
    private Psr17Factory $psr17;

    protected function setUp(): void
    {
        $this->psr17 = new Psr17Factory();
    }

    public function testGetServiceFormatUsesBasicAuth(): void
    {
        $http = new MockPsr18Client();
        $http->enqueue(
            $this->psr17->createResponse(200)->withBody(
                $this->psr17->createStream(Fixtures::read('client_iam_service_format.json')),
            ),
        );

        $client = new Psr18ClientIamHttpClient(
            TestEnvironment::create(['clientId' => 'my-client', 'clientSecret' => 's3cret']),
            $http,
            $this->psr17,
            $this->psr17,
        );

        $format = $client->getServiceFormat();

        $request = $http->requests[0];
        $expected = 'Basic ' . base64_encode('my-client:s3cret');
        $this->assertSame($expected, $request->getHeaderLine('Authorization'));
        $this->assertStringEndsWith('/resources/client/service-format', (string) $request->getUri());
        $this->assertSame('Skbc', $format->iamService);
        $this->assertSame(['Configuration', 'Kingdom'], $format->serviceFormat);
        $this->assertFalse($format->isDefault);
    }

    public function testAddPolicyClaimPostsExpectedBody(): void
    {
        $http = new MockPsr18Client();
        $http->enqueue($this->psr17->createResponse(204));

        $client = $this->createClient($http);
        $client->addPolicyClaim(
            '550e8400-e29b-41d4-a716-446655440000',
            ':0:123:',
            'Editor/Write',
        );

        $request = $http->requests[0];
        $this->assertSame('POST', $request->getMethod());
        $body = json_decode((string) $request->getBody(), true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame('550e8400-e29b-41d4-a716-446655440000', $body['idp_user_id']);
        $this->assertSame(':0:123:', $body['provisos']);
        $this->assertSame('Editor/Write', $body['resource']);
    }

    public function testListPolicyClaims(): void
    {
        $http = new MockPsr18Client();
        $http->enqueue(
            $this->psr17->createResponse(200)->withBody(
                $this->psr17->createStream(Fixtures::read('client_iam_policy_claims.json')),
            ),
        );

        $client = $this->createClient($http);
        $list = $client->listPolicyClaims('550e8400-e29b-41d4-a716-446655440000');

        $this->assertCount(1, $list->claims);
        $this->assertSame('Skbc', $list->claims[0]->service);
        $this->assertSame(':0:123:', $list->claims[0]->provisos);
    }

    public function testPutUserMetadata(): void
    {
        $http = new MockPsr18Client();
        $http->enqueue($this->psr17->createResponse(204));

        $client = $this->createClient($http);
        $client->putUserMetadata(
            '550e8400-e29b-41d4-a716-446655440000',
            42,
            ['tier' => 2],
            'json',
        );

        $body = json_decode((string) $http->requests[0]->getBody(), true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame(42, $body['login_id']);
        $this->assertSame(['tier' => 2], $body['metadata']);
    }

    public function testGetUserMetadata(): void
    {
        $http = new MockPsr18Client();
        $http->enqueue(
            $this->psr17->createResponse(200)->withBody(
                $this->psr17->createStream(Fixtures::read('client_iam_user_metadata.json')),
            ),
        );

        $client = $this->createClient($http);
        $metadata = $client->getUserMetadata('550e8400-e29b-41d4-a716-446655440000', 42);

        $this->assertSame(42, $metadata->loginId);
        $this->assertSame(['tier' => 2], $metadata->metadata);
        $this->assertSame('json', $metadata->encoding);
    }

    public function testUnauthorizedMapsToClientIamException(): void
    {
        $http = new MockPsr18Client();
        $http->enqueue(
            $this->psr17->createResponse(401)->withBody(
                $this->psr17->createStream('{"error":"Invalid client credentials."}'),
            ),
        );

        $client = $this->createClient($http);

        try {
            $client->getServiceFormat();
            $this->fail('Expected ClientIamException');
        } catch (ClientIamException $exception) {
            $this->assertSame(ErrorCode::ClientIamUnauthorized, $exception->errorCode());
            $this->assertSame('Invalid client credentials.', $exception->idpError());
        }
    }

    public function testValidationErrorMapsTo400(): void
    {
        $http = new MockPsr18Client();
        $http->enqueue(
            $this->psr17->createResponse(400)->withBody(
                $this->psr17->createStream('{"error":"idp_user_id is required"}'),
            ),
        );

        $client = $this->createClient($http);

        try {
            $client->addPolicyClaim('', ':0:', 'Editor/Write');
            $this->fail('Expected ClientIamException');
        } catch (ClientIamException $exception) {
            $this->assertSame(ErrorCode::ClientIamValidation, $exception->errorCode());
        }
    }

    public function testNotFoundMapsTo404(): void
    {
        $http = new MockPsr18Client();
        $http->enqueue(
            $this->psr17->createResponse(404)->withBody(
                $this->psr17->createStream('{"error":"unknown idp_user_id"}'),
            ),
        );

        $client = $this->createClient($http);

        try {
            $client->listPolicyClaims('550e8400-e29b-41d4-a716-446655440000');
            $this->fail('Expected ClientIamException');
        } catch (ClientIamException $exception) {
            $this->assertSame(ErrorCode::ClientIamNotFound, $exception->errorCode());
        }
    }

    public function testConflictMapsTo409(): void
    {
        $http = new MockPsr18Client();
        $http->enqueue(
            $this->psr17->createResponse(409)->withBody(
                $this->psr17->createStream('{"error":"service format already configured; use PUT to replace"}'),
            ),
        );

        $client = $this->createClient($http);

        try {
            $client->createServiceFormat(['Configuration', 'Kingdom']);
            $this->fail('Expected ClientIamException');
        } catch (ClientIamException $exception) {
            $this->assertSame(ErrorCode::ClientIamConflict, $exception->errorCode());
        }
    }

    public function testMissingSecretThrowsBeforeHttp(): void
    {
        $client = new Psr18ClientIamHttpClient(
            TestEnvironment::create(['clientSecret' => null]),
            new MockPsr18Client(),
            $this->psr17,
            $this->psr17,
        );

        try {
            $client->getServiceFormat();
            $this->fail('Expected ClientIamException');
        } catch (ClientIamException $exception) {
            $this->assertSame(ErrorCode::ClientIamMissingSecret, $exception->errorCode());
        }
    }

    public function testHttpTransportFailure(): void
    {
        $client = new Psr18ClientIamHttpClient(
            TestEnvironment::create(),
            new ThrowingHttpClient(),
            $this->psr17,
            $this->psr17,
        );

        try {
            $client->getServiceFormat();
            $this->fail('Expected ClientIamException');
        } catch (ClientIamException $exception) {
            $this->assertSame(ErrorCode::HttpTransport, $exception->errorCode());
        }
    }

    private function createClient(MockPsr18Client $http): Psr18ClientIamHttpClient
    {
        return new Psr18ClientIamHttpClient(
            TestEnvironment::create(),
            $http,
            $this->psr17,
            $this->psr17,
        );
    }
}
