<?php

declare(strict_types=1);

namespace Amtgard\IdpClient\Tests\ClientIam;

use Amtgard\IdpClient\ClientIam\ClientIamClient;
use Amtgard\IdpClient\ClientIam\Http\Psr18ClientIamHttpClient;
use Amtgard\IdpClient\ClientIam\Iam\IntegratorOrnRegistrar;
use Amtgard\IdpClient\ClientIam\Model\ServiceFormatRequest;
use Amtgard\IdpClient\ClientIam\Model\UserMetadataRequest;
use Amtgard\IdpClient\ClientIam\Validation\ServiceFormatValidator;
use Amtgard\IdpClient\Exception\ClientIamException;
use Amtgard\IdpClient\Exception\ErrorCode;
use Amtgard\IdpClient\Tests\Support\Fixtures;
use Amtgard\IdpClient\Tests\Support\MockPsr18Client;
use Amtgard\IdpClient\Tests\Support\TestEnvironment;
use Nyholm\Psr7\Factory\Psr17Factory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(ServiceFormatValidator::class)]
final class ClientIamExtendedTest extends TestCase
{
    private Psr17Factory $psr17;

    protected function setUp(): void
    {
        $this->psr17 = new Psr17Factory();
    }

    protected function tearDown(): void
    {
        IntegratorOrnRegistrar::reset();
    }

    public function testDeletePolicyClaimAndListClaims(): void
    {
        $http = new MockPsr18Client();
        $http->enqueue($this->psr17->createResponse(204));
        $http->enqueue(
            $this->psr17->createResponse(200)->withBody(
                $this->psr17->createStream(Fixtures::read('client_iam_policy_claims.json')),
            ),
        );

        $client = $this->client($http);
        $claim = $client->composeClaim(['Configuration' => 0, 'Kingdom' => 123], 'Editor/Write');

        $client->deletePolicyClaim('550e8400-e29b-41d4-a716-446655440000', $claim);
        $list = $client->listPolicyClaims('550e8400-e29b-41d4-a716-446655440000');

        $this->assertSame('DELETE', $http->requests[0]->getMethod());
        $this->assertCount(1, $list->claims);
    }

    public function testAddPolicyClaimFromOrn(): void
    {
        $http = new MockPsr18Client();
        $http->enqueue($this->psr17->createResponse(204));

        $client = $this->client($http);
        $client->addPolicyClaimFromOrn(
            '550e8400-e29b-41d4-a716-446655440000',
            'Skbc:0:123:Editor/Write',
        );

        $this->assertSame('POST', $http->requests[0]->getMethod());
    }

    public function testCreateAndReplaceServiceFormat(): void
    {
        $http = new MockPsr18Client();
        $http->enqueue($this->psr17->createResponse(204));
        $http->enqueue($this->psr17->createResponse(204));

        $client = $this->client($http);
        $client->createServiceFormat(new ServiceFormatRequest(['Configuration', 'Kingdom']));
        $client->replaceServiceFormat(new ServiceFormatRequest(['Configuration', 'Park']));

        $this->assertSame('POST', $http->requests[0]->getMethod());
        $this->assertSame('PUT', $http->requests[1]->getMethod());
    }

    public function testUserMetadataLifecycle(): void
    {
        $http = new MockPsr18Client();
        $http->enqueue($this->psr17->createResponse(204));
        $http->enqueue(
            $this->psr17->createResponse(200)->withBody(
                $this->psr17->createStream(Fixtures::read('client_iam_user_metadata.json')),
            ),
        );
        $http->enqueue($this->psr17->createResponse(204));

        $client = $this->client($http);
        $client->putUserMetadata(new UserMetadataRequest(
            idpUserId: '550e8400-e29b-41d4-a716-446655440000',
            loginId: 42,
            metadata: ['tier' => 2],
        ));
        $metadata = $client->getUserMetadata('550e8400-e29b-41d4-a716-446655440000', 42);
        $client->deleteUserMetadata('550e8400-e29b-41d4-a716-446655440000', 42);

        $this->assertSame(42, $metadata->loginId);
        $this->assertSame('DELETE', $http->requests[2]->getMethod());
    }

    public function testIamServiceAndServiceFormatSlotsExposeOfflineConfig(): void
    {
        $client = $this->client(new MockPsr18Client());

        $this->assertSame('Skbc', $client->iamService());
        $this->assertSame(['Configuration', 'Kingdom'], array_map(
            static fn ($slot) => is_string($slot) ? $slot : $slot->value,
            $client->serviceFormatSlots(),
        ));
    }

    public function testServiceFormatValidatorRejectsInvalidSlot(): void
    {
        try {
            ServiceFormatValidator::validate(new ServiceFormatRequest(['']));
            $this->fail('Expected ClientIamException');
        } catch (ClientIamException $exception) {
            $this->assertSame(ErrorCode::ClientIamValidation, $exception->errorCode());
        }
    }

    public function testClientIamHttpMalformedJsonAndUnexpectedStatus(): void
    {
        $http = new MockPsr18Client();
        $http->enqueue($this->psr17->createResponse(200)->withBody($this->psr17->createStream('not-json')));

        $client = new Psr18ClientIamHttpClient(
            TestEnvironment::create(),
            $http,
            $this->psr17,
            $this->psr17,
        );

        try {
            $client->getServiceFormat();
            $this->fail('Expected ClientIamException');
        } catch (ClientIamException $exception) {
            $this->assertSame(ErrorCode::ClientIamMalformedJson, $exception->errorCode());
        }

        $http2 = new MockPsr18Client();
        $http2->enqueue($this->psr17->createResponse(500)->withBody($this->psr17->createStream('error')));
        $client2 = new Psr18ClientIamHttpClient(
            TestEnvironment::create(),
            $http2,
            $this->psr17,
            $this->psr17,
        );

        try {
            $client2->getServiceFormat();
            $this->fail('Expected ClientIamException');
        } catch (ClientIamException $exception) {
            $this->assertSame(ErrorCode::ClientIamUnexpectedStatus, $exception->errorCode());
        }
    }

    public function testPolicyClaimFullOrn(): void
    {
        $client = $this->client(new MockPsr18Client());
        $claim = $client->composeClaim(['Configuration' => 0], 'Editor/Write');

        $this->assertStringStartsWith('Skbc:', $claim->buildOrn());
    }

    private function client(MockPsr18Client $http): ClientIamClient
    {
        $environment = TestEnvironment::create([
            'iamService' => 'Skbc',
            'iamServiceFormat' => ['Configuration', 'Kingdom'],
        ]);

        return new ClientIamClient(
            new Psr18ClientIamHttpClient($environment, $http, $this->psr17, $this->psr17),
            $environment->iamService(),
            $environment->iamServiceFormat(),
        );
    }
}
