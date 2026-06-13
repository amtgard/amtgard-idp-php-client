<?php

declare(strict_types=1);

namespace Amtgard\IdpClient\ClientIam\Validation;

use Amtgard\IAM\ORN\OrnSegmentLabel;
use Amtgard\IdpClient\ClientIam\Model\ServiceFormatRequest;
use Amtgard\IdpClient\Exception\ClientIamException;
use Amtgard\IdpClient\Exception\ErrorCode;

final class ServiceFormatValidator
{
    public static function validate(ServiceFormatRequest $request): void
    {
        if ($request->serviceFormat === []) {
            throw new ClientIamException(
                ErrorCode::ClientIamValidation,
                'service_format must be a non-empty array.',
            );
        }

        foreach ($request->serviceFormat as $slot) {
            if (trim($slot) === '') {
                throw new ClientIamException(
                    ErrorCode::ClientIamValidation,
                    'service_format entries must be non-empty strings.',
                );
            }

            try {
                OrnSegmentLabel::from(trim($slot));
            } catch (\Throwable $exception) {
                throw new ClientIamException(
                    ErrorCode::ClientIamValidation,
                    sprintf('Invalid service_format slot name "%s".', $slot),
                    previous: $exception,
                );
            }
        }
    }
}
