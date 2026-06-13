<?php

declare(strict_types=1);

namespace Amtgard\IdpClient\Tests\ClientIam;

use Amtgard\IAM\ClaimFactory;
use Amtgard\IAM\OrkServices;
use Amtgard\IdpClient\Client\IdpClient;
use Amtgard\IdpClient\ClientIam\ClientIamClient;
use Amtgard\IdpClient\ClientIam\Http\Psr18ClientIamHttpClient;
use Amtgard\IdpClient\ClientIam\Iam\IntegratorOrnRegistrar;
use Amtgard\IdpClient\ClientIam\Model\PolicyClaim;
use Amtgard\IdpClient\ClientIam\Model\PolicyClaimList;
use Amtgard\IdpClient\ClientIam\Model\ServiceFormatRequest;
use Amtgard\IdpClient\ClientIam\Model\UserMetadataRequest;
use Amtgard\IdpClient\Exception\ClientIamException;
use Amtgard\IdpClient\Exception\ErrorCode;
use Amtgard\IdpClient\Exception\IdpConfigurationException;
use Amtgard\IdpClient\Iam\OrnWireFormat;
use Amtgard\IdpClient\OAuth\InMemoryOAuthFlowStateStore;
use Amtgard\IdpClient\Tests\Support\Fixtures;
use Amtgard\IdpClient\Tests\Support\MockPsr18Client;
use Amtgard\IdpClient\Tests\Support\TestEnvironment;
use Nyholm\Psr7\Factory\Psr17Factory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(ClientIamClient::class)]
final class ClientIamClientTest extends TestCase
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

    public function testRequireSecretThrowsConfigurationException(): void
    {
        $this->expectException(IdpConfigurationException::class);
        ClientIamClient::requireSecret(TestEnvironment::create(['clientSecret' => null]));
    }

    public function testGetServiceFormatCachesResult(): void
    {
        $http = new MockPsr18Client();
        $http->enqueue(
            $this->psr17->createResponse(200)->withBody(
                $this->psr17->createStream(Fixtures::read('client_iam_service_format.json')),
            ),
        );

        $client = $this->createClientIamClient($http);
        $first = $client->getServiceFormat();
        $second = $client->getServiceFormat();

        $this->assertSame($first->iamService, $second->iamService);
        $this->assertCount(1, $http->requests);
    }

    public function testComposeClaimUsesCachedFormat(): void
    {
        $http = new MockPsr18Client();
        $http->enqueue(
            $this->psr17->createResponse(200)->withBody(
                $this->psr17->createStream(Fixtures::read('client_iam_service_format.json')),
            ),
        );

        $client = $this->createClientIamClient($http);
        $claim = $client->composeClaim(['Configuration' => 0, 'Kingdom' => 123], 'Editor/Write');

        $this->assertSame('Skbc:0:123:Editor/Write', $claim->buildOrn());
    }

    public function testComposeClaimUsesOfflineEnvWithoutGet(): void
    {
        $client = $this->createClientIamClient(
            new MockPsr18Client(),
            iamService: 'Skbc',
            iamServiceFormat: ['Configuration', 'Kingdom'],
        );

        $claim = $client->composeClaim(['Configuration' => 0, 'Kingdom' => 123], 'Editor/Write');

        $this->assertSame('Skbc:0:123:Editor/Write', $claim->buildOrn());
    }

    public function testAddPolicyClaimSerializesWireParts(): void
    {
        $http = new MockPsr18Client();
        $http->enqueue(
            $this->psr17->createResponse(200)->withBody(
                $this->psr17->createStream(Fixtures::read('client_iam_service_format.json')),
            ),
        );
        $http->enqueue($this->psr17->createResponse(204));

        $client = $this->createClientIamClient($http);
        $claim = $client->composeClaim(['Configuration' => 0, 'Kingdom' => 123], 'Editor/Write');
        $client->addPolicyClaim('550e8400-e29b-41d4-a716-446655440000', $claim);

        $post = $http->requests[1];
        $body = json_decode((string) $post->getBody(), true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame(':0:123:', $body['provisos']);
        $this->assertSame('Editor/Write', $body['resource']);
    }

    public function testAddPolicyClaimRejectsPrefixMismatch(): void
    {
        $client = $this->createClientIamClient(
            new MockPsr18Client(),
            iamService: 'Skbc',
            iamServiceFormat: ['Configuration', 'Kingdom'],
        );

        IntegratorOrnRegistrar::register('Other', [OrkServices::Configuration]);
        $orn = OrnWireFormat::composeFullOrn('Other', [OrkServices::Configuration], ['Configuration' => 0], 'Editor/Write');
        $claim = \Amtgard\IAM\ClaimFactory::createOrn($orn);

        try {
            $client->addPolicyClaim('550e8400-e29b-41d4-a716-446655440000', $claim);
            $this->fail('Expected ClientIamException');
        } catch (ClientIamException $exception) {
            $this->assertSame(ErrorCode::ClientIamInvalidOrn, $exception->errorCode());
        }
    }

    public function testPolicyFromStoredClaimsBuildsPolicy(): void
    {
        $client = $this->createClientIamClient(
            new MockPsr18Client(),
            iamService: 'Skbc',
            iamServiceFormat: ['Configuration', 'Kingdom'],
        );

        $list = new PolicyClaimList([
            new PolicyClaim('Skbc', ':0:123:', 'Editor/Write'),
        ]);

        $policy = $client->policyFromStoredClaims($list);

        $this->assertNotEmpty($policy->getClaims());
    }

    public function testUserMetadataValidationBeforeHttp(): void
    {
        $http = new MockPsr18Client();
        $client = $this->createClientIamClient($http);

        try {
            $client->putUserMetadata(new UserMetadataRequest(
                idpUserId: '',
                loginId: 0,
                metadata: [],
            ));
            $this->fail('Expected ClientIamException');
        } catch (ClientIamException $exception) {
            $this->assertSame(ErrorCode::ClientIamValidation, $exception->errorCode());
        }

        $this->assertCount(0, $http->requests);
    }

    public function testServiceFormatValidationBeforeHttp(): void
    {
        $http = new MockPsr18Client();
        $client = $this->createClientIamClient($http);

        try {
            $client->createServiceFormat(new ServiceFormatRequest([]));
            $this->fail('Expected ClientIamException');
        } catch (ClientIamException $exception) {
            $this->assertSame(ErrorCode::ClientIamValidation, $exception->errorCode());
        }

        $this->assertCount(0, $http->requests);
    }

    public function testIdpClientExposesClientIamSubClient(): void
    {
        $http = new MockPsr18Client();
        $idp = new IdpClient(
            TestEnvironment::create([
                'iamService' => 'Skbc',
                'iamServiceFormat' => ['Configuration', 'Kingdom'],
            ]),
            new InMemoryOAuthFlowStateStore(),
            $http,
            $this->psr17,
            $this->psr17,
        );

        $iam = $idp->clientIam();
        $this->assertInstanceOf(ClientIamClient::class, $iam);
        $this->assertSame($iam, $idp->clientIam());
    }

    public function testIdpClientClientIamRequiresSecret(): void
    {
        $idp = new IdpClient(
            TestEnvironment::create(['clientSecret' => null]),
            new InMemoryOAuthFlowStateStore(),
            new MockPsr18Client(),
            $this->psr17,
            $this->psr17,
        );

        $this->expectException(IdpConfigurationException::class);
        $idp->clientIam();
    }

    private function createClientIamClient(
        MockPsr18Client $http,
        ?string $iamService = null,
        ?array $iamServiceFormat = null,
    ): ClientIamClient {
        $environment = TestEnvironment::create([
            'iamService' => $iamService,
            'iamServiceFormat' => $iamServiceFormat,
        ]);

        return new ClientIamClient(
            new Psr18ClientIamHttpClient($environment, $http, $this->psr17, $this->psr17),
            $environment->iamService(),
            $environment->iamServiceFormat(),
        );
    }
}
