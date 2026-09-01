<?php

declare(strict_types=1);

namespace Amtgard\IdpClient\Tests\ClientIam\Validation;

use Amtgard\IAM\Catalog\ServiceCatalog;
use Amtgard\IAM\ClaimFactory;
use Amtgard\IdpClient\ClientIam\Iam\IntegratorOrnRegistrar;
use Amtgard\IdpClient\ClientIam\Model\ServiceFormatRequest;
use Amtgard\IdpClient\ClientIam\Model\UserMetadataRequest;
use Amtgard\IdpClient\ClientIam\Validation\PolicyClaimValidator;
use Amtgard\IdpClient\ClientIam\Validation\ServiceFormatValidator;
use Amtgard\IdpClient\ClientIam\Validation\UserMetadataValidator;
use Amtgard\IdpClient\Exception\ClientIamException;
use Amtgard\IdpClient\Exception\ErrorCode;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(PolicyClaimValidator::class)]
#[CoversClass(ServiceFormatValidator::class)]
#[CoversClass(UserMetadataValidator::class)]
final class ClientIamValidatorsTest extends TestCase
{
    protected function tearDown(): void
    {
        IntegratorOrnRegistrar::reset();
    }

    public function testServiceFormatAcceptsCatalogSlotNames(): void
    {
        ServiceFormatValidator::validate(new ServiceFormatRequest(['Configuration', 'Kingdom', 'Park']));

        $this->addToAssertionCount(1);
    }

    public function testServiceFormatRejectsEmptyArray(): void
    {
        try {
            ServiceFormatValidator::validate(new ServiceFormatRequest([]));
            $this->fail('Expected ClientIamException');
        } catch (ClientIamException $exception) {
            $this->assertSame(ErrorCode::ClientIamValidation, $exception->errorCode());
            $this->assertStringContainsString('non-empty', $exception->getMessage());
        }
    }

    public function testServiceFormatAllowsCustomNonEmptySlotNames(): void
    {
        // ork-iam 2.x OrnSegmentLabel::from accepts non-catalog labels; only empty is rejected.
        ServiceFormatValidator::validate(new ServiceFormatRequest(['CustomIntegratorSlot']));

        $this->addToAssertionCount(1);
    }

    public function testServiceFormatRejectsWhitespaceOnlySlot(): void
    {
        try {
            ServiceFormatValidator::validate(new ServiceFormatRequest(['   ']));
            $this->fail('Expected ClientIamException');
        } catch (ClientIamException $exception) {
            $this->assertSame(ErrorCode::ClientIamValidation, $exception->errorCode());
            $this->assertStringContainsString('non-empty', $exception->getMessage());
        }
    }

    public function testMetadataRejectsNonPositiveLoginId(): void
    {
        try {
            UserMetadataValidator::validate(new UserMetadataRequest(
                idpUserId: '550e8400-e29b-41d4-a716-446655440000',
                loginId: 0,
                metadata: ['tier' => 1],
            ));
            $this->fail('Expected ClientIamException');
        } catch (ClientIamException $exception) {
            $this->assertSame(ErrorCode::ClientIamValidation, $exception->errorCode());
            $this->assertStringContainsString('login_id', $exception->getMessage());
        }
    }

    public function testMetadataRejectsUnknownEncoding(): void
    {
        try {
            UserMetadataValidator::validate(new UserMetadataRequest(
                idpUserId: '550e8400-e29b-41d4-a716-446655440000',
                loginId: 1,
                metadata: ['tier' => 1],
                encoding: 'xml',
            ));
            $this->fail('Expected ClientIamException');
        } catch (ClientIamException $exception) {
            $this->assertSame(ErrorCode::ClientIamValidation, $exception->errorCode());
            $this->assertStringContainsString('encoding', $exception->getMessage());
        }
    }

    public function testMetadataRejectsEmptyBase64String(): void
    {
        try {
            UserMetadataValidator::validate(new UserMetadataRequest(
                idpUserId: '550e8400-e29b-41d4-a716-446655440000',
                loginId: 1,
                metadata: '   ',
                encoding: 'base64',
            ));
            $this->fail('Expected ClientIamException');
        } catch (ClientIamException $exception) {
            $this->assertSame(ErrorCode::ClientIamValidation, $exception->errorCode());
            $this->assertStringContainsString('base64', $exception->getMessage());
        }
    }

    public function testMetadataRejectsBase64NonJsonPayload(): void
    {
        $encoded = base64_encode('not-json');

        try {
            UserMetadataValidator::validate(new UserMetadataRequest(
                idpUserId: '550e8400-e29b-41d4-a716-446655440000',
                loginId: 1,
                metadata: $encoded,
                encoding: 'base64',
            ));
            $this->fail('Expected ClientIamException');
        } catch (ClientIamException $exception) {
            $this->assertSame(ErrorCode::ClientIamValidation, $exception->errorCode());
            $this->assertNotNull($exception->getPrevious());
        }
    }

    public function testMetadataRejectsInvalidBase64(): void
    {
        try {
            UserMetadataValidator::validate(new UserMetadataRequest(
                idpUserId: '550e8400-e29b-41d4-a716-446655440000',
                loginId: 1,
                metadata: '!!!not-base64!!!',
                encoding: 'base64',
            ));
            $this->fail('Expected ClientIamException');
        } catch (ClientIamException $exception) {
            $this->assertSame(ErrorCode::ClientIamValidation, $exception->errorCode());
        }
    }

    public function testMetadataRejectsBase64ListPayload(): void
    {
        $encoded = base64_encode(json_encode(['a', 'b'], JSON_THROW_ON_ERROR));

        try {
            UserMetadataValidator::validate(new UserMetadataRequest(
                idpUserId: '550e8400-e29b-41d4-a716-446655440000',
                loginId: 1,
                metadata: $encoded,
                encoding: 'base64',
            ));
            $this->fail('Expected ClientIamException');
        } catch (ClientIamException $exception) {
            $this->assertSame(ErrorCode::ClientIamValidation, $exception->errorCode());
            $this->assertStringContainsString('JSON object', $exception->getMessage());
        }
    }

    public function testMetadataRejectsArrayPayload(): void
    {
        try {
            UserMetadataValidator::validate(new UserMetadataRequest(
                idpUserId: '550e8400-e29b-41d4-a716-446655440000',
                loginId: 1,
                metadata: ['a', 'b'],
            ));
            $this->fail('Expected ClientIamException');
        } catch (ClientIamException $exception) {
            $this->assertSame(ErrorCode::ClientIamValidation, $exception->errorCode());
        }
    }

    public function testMetadataRejectsOversizeJson(): void
    {
        $payload = ['key' => str_repeat('x', 301)];

        try {
            UserMetadataValidator::validate(new UserMetadataRequest(
                idpUserId: '550e8400-e29b-41d4-a716-446655440000',
                loginId: 1,
                metadata: $payload,
            ));
            $this->fail('Expected ClientIamException');
        } catch (ClientIamException $exception) {
            $this->assertSame(ErrorCode::ClientIamValidation, $exception->errorCode());
        }
    }

    public function testMetadataValidatesBase64RoundTrip(): void
    {
        $json = json_encode(['tier' => 2], JSON_THROW_ON_ERROR);
        $encoded = base64_encode($json);

        UserMetadataValidator::validate(new UserMetadataRequest(
            idpUserId: '550e8400-e29b-41d4-a716-446655440000',
            loginId: 1,
            metadata: $encoded,
            encoding: 'base64',
        ));

        $this->addToAssertionCount(1);
    }

    public function testPolicyClaimValidatorRejectsLongProvisos(): void
    {
        try {
            PolicyClaimValidator::validateOrnParts(str_repeat('x', 51), 'Editor/Write');
            $this->fail('Expected ClientIamException');
        } catch (ClientIamException $exception) {
            $this->assertSame(ErrorCode::ClientIamValidation, $exception->errorCode());
        }
    }

    public function testPolicyClaimValidatorRejectsEmptyPartsAndUserId(): void
    {
        try {
            PolicyClaimValidator::validateOrnParts('', 'Editor/Write');
            $this->fail('Expected ClientIamException');
        } catch (ClientIamException $exception) {
            $this->assertSame(ErrorCode::ClientIamValidation, $exception->errorCode());
        }

        try {
            PolicyClaimValidator::validateIdpUserId('   ');
            $this->fail('Expected ClientIamException');
        } catch (ClientIamException $exception) {
            $this->assertSame(ErrorCode::ClientIamValidation, $exception->errorCode());
        }
    }

    public function testPolicyClaimValidatorAcceptsMatchingClaim(): void
    {
        IntegratorOrnRegistrar::register('Skbc', [ServiceCatalog::Configuration, ServiceCatalog::Kingdom]);
        $claim = ClaimFactory::createOrn('Skbc:0:123:Editor/Write');

        PolicyClaimValidator::validateClaim(
            '550e8400-e29b-41d4-a716-446655440000',
            $claim,
            'Skbc',
            [ServiceCatalog::Configuration, ServiceCatalog::Kingdom],
        );

        $this->addToAssertionCount(1);
    }

    public function testPolicyClaimValidatorRejectsPrefixMismatch(): void
    {
        IntegratorOrnRegistrar::register('Other', [ServiceCatalog::Configuration]);
        $claim = ClaimFactory::createOrn('Other:0:Editor/Write');

        try {
            PolicyClaimValidator::validateClaim(
                '550e8400-e29b-41d4-a716-446655440000',
                $claim,
                'Skbc',
                [ServiceCatalog::Configuration, ServiceCatalog::Kingdom],
            );
            $this->fail('Expected ClientIamException');
        } catch (ClientIamException $exception) {
            $this->assertSame(ErrorCode::ClientIamInvalidOrn, $exception->errorCode());
        }
    }

    public function testPolicyClaimValidatorRejectsOrnThatFailsFactoryRebuild(): void
    {
        IntegratorOrnRegistrar::register('M4BadRebuild', [ServiceCatalog::Configuration, ServiceCatalog::Kingdom]);
        $claim = ClaimFactory::createOrn('M4BadRebuild:0:123:Editor/Write');

        try {
            // Re-register with a shorter schema so recreate via ClaimFactory fails.
            PolicyClaimValidator::validateClaim(
                '550e8400-e29b-41d4-a716-446655440000',
                $claim,
                'M4BadRebuild',
                [ServiceCatalog::Configuration],
            );
            $this->fail('Expected ClientIamException');
        } catch (ClientIamException $exception) {
            $this->assertSame(ErrorCode::ClientIamInvalidOrn, $exception->errorCode());
        }
    }
}
