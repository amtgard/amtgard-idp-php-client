<?php

declare(strict_types=1);

namespace Amtgard\IdpClient\ClientIam\Validation;

use Amtgard\IdpClient\ClientIam\Model\UserMetadataRequest;
use Amtgard\IdpClient\Exception\ClientIamException;
use Amtgard\IdpClient\Exception\ErrorCode;

final class UserMetadataValidator
{
    private const MAX_BYTES = 300;

    public static function validate(UserMetadataRequest $request): void
    {
        PolicyClaimValidator::validateIdpUserId($request->idpUserId);

        if ($request->loginId <= 0) {
            throw new ClientIamException(
                ErrorCode::ClientIamValidation,
                'login_id is required.',
            );
        }

        $encoding = strtolower($request->encoding);
        if (!in_array($encoding, ['json', 'base64'], true)) {
            throw new ClientIamException(
                ErrorCode::ClientIamValidation,
                'encoding must be json or base64.',
            );
        }

        if ($encoding === 'base64') {
            self::validateBase64Metadata($request->metadata);

            return;
        }

        self::validateJsonMetadata($request->metadata);
    }

    private static function validateJsonMetadata(mixed $metadata): void
    {
        if (!is_array($metadata) || array_is_list($metadata)) {
            throw new ClientIamException(
                ErrorCode::ClientIamValidation,
                'metadata must be a JSON object.',
            );
        }

        self::assertMaxBytes(json_encode($metadata, JSON_THROW_ON_ERROR));
    }

    private static function validateBase64Metadata(mixed $metadata): void
    {
        if (!is_string($metadata) || trim($metadata) === '') {
            throw new ClientIamException(
                ErrorCode::ClientIamValidation,
                'metadata must be a non-empty base64 string when encoding is base64.',
            );
        }

        self::assertMaxBytes($metadata);

        $decoded = base64_decode($metadata, true);
        if ($decoded === false) {
            throw new ClientIamException(
                ErrorCode::ClientIamValidation,
                'metadata must be valid base64 when encoding is base64.',
            );
        }

        try {
            $object = json_decode($decoded, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $exception) {
            throw new ClientIamException(
                ErrorCode::ClientIamValidation,
                'base64 metadata must decode to a JSON object.',
                previous: $exception,
            );
        }

        if (!is_array($object) || array_is_list($object)) {
            throw new ClientIamException(
                ErrorCode::ClientIamValidation,
                'base64 metadata must decode to a JSON object.',
            );
        }

        self::assertMaxBytes($decoded);
    }

    private static function assertMaxBytes(string $payload): void
    {
        if (strlen($payload) > self::MAX_BYTES) {
            throw new ClientIamException(
                ErrorCode::ClientIamValidation,
                sprintf('metadata must be at most %d bytes.', self::MAX_BYTES),
            );
        }
    }
}
