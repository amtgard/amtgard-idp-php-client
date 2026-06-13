<?php

declare(strict_types=1);

namespace Amtgard\IdpClient\Tests\ClientIam\Validation;

use Amtgard\IAM\Allowance\ClaimBuilder;
use Amtgard\IdpClient\ClientIam\Iam\IntegratorOrnRegistrar;
use Amtgard\IdpClient\ClientIam\Model\UserMetadataRequest;
use Amtgard\IdpClient\ClientIam\Validation\PolicyClaimValidator;
use Amtgard\IdpClient\ClientIam\Validation\UserMetadataValidator;
use Amtgard\IdpClient\Exception\ClientIamException;
use Amtgard\IdpClient\Exception\ErrorCode;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(PolicyClaimValidator::class)]
#[CoversClass(UserMetadataValidator::class)]
final class ClientIamValidatorsTest extends TestCase
{
    protected function tearDown(): void
    {
        IntegratorOrnRegistrar::reset();
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
}
