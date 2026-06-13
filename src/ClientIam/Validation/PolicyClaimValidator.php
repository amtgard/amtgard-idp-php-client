<?php

declare(strict_types=1);

namespace Amtgard\IdpClient\ClientIam\Validation;

use Amtgard\IAM\Allowance\Claim;
use Amtgard\IAM\ClaimFactory;
use Amtgard\IdpClient\ClientIam\Iam\IntegratorOrnRegistrar;
use Amtgard\IdpClient\Exception\ClientIamException;
use Amtgard\IdpClient\Exception\ErrorCode;
use Amtgard\IdpClient\Iam\OrnWireFormat;

final class PolicyClaimValidator
{
    private const MAX_PART_LENGTH = 50;

    /**
     * @param list<\Amtgard\IAM\OrkServices|string> $format
     */
    public static function validateClaim(
        string $idpUserId,
        Claim $claim,
        string $iamService,
        array $format,
    ): void {
        self::validateIdpUserId($idpUserId);

        $parts = OrnWireFormat::fromClaim($claim);
        self::validateOrnParts($parts->provisos, $parts->resource);

        if ($parts->prefix !== $iamService) {
            throw new ClientIamException(
                ErrorCode::ClientIamInvalidOrn,
                sprintf(
                    'Claim prefix "%s" does not match client iam_service "%s".',
                    $parts->prefix,
                    $iamService,
                ),
            );
        }

        IntegratorOrnRegistrar::register($iamService, $format);

        try {
            ClaimFactory::createOrn($parts->fullOrn());
        } catch (\Throwable $exception) {
            throw new ClientIamException(
                ErrorCode::ClientIamInvalidOrn,
                'Invalid ORN claim: ' . $exception->getMessage(),
                previous: $exception,
            );
        }
    }

    public static function validateOrnParts(string $provisos, string $resource): void
    {
        foreach ([['provisos', $provisos], ['resource', $resource]] as [$label, $value]) {
            if ($value === '') {
                throw new ClientIamException(
                    ErrorCode::ClientIamValidation,
                    sprintf('%s is required.', $label),
                );
            }

            if (strlen($value) > self::MAX_PART_LENGTH) {
                throw new ClientIamException(
                    ErrorCode::ClientIamValidation,
                    sprintf('%s must be at most %d characters.', $label, self::MAX_PART_LENGTH),
                );
            }
        }
    }

    public static function validateIdpUserId(string $idpUserId): void
    {
        if (trim($idpUserId) === '') {
            throw new ClientIamException(
                ErrorCode::ClientIamValidation,
                'idp_user_id is required.',
            );
        }
    }
}
