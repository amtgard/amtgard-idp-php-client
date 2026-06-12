<?php

declare(strict_types=1);

namespace Amtgard\IdpClient\Iam;

use Amtgard\IAM\Allowance\Policy;
use Amtgard\IAM\PolicyFactory;
use Amtgard\IAM\Requirement\Requirement;
use Amtgard\IAM\RequirementFactory;
use Amtgard\IdpClient\Exception\ErrorCode;
use Amtgard\IdpClient\Exception\ResourceException;

/**
 * Parses ORN strings into ork-iam Policy and Requirement objects.
 */
final class OrnParser
{
    public function __construct()
    {
        OrnBootstrap::register();
    }

    /**
     * @param list<string> $orns ORN claim strings (JWT policy claim shape)
     */
    public function policyFromOrns(array $orns): Policy
    {
        try {
            return PolicyFactory::fromOrn($orns);
        } catch (\Throwable $exception) {
            throw $this->invalidOrn($exception);
        }
    }

    public function requirementFromOrn(string $orn): Requirement
    {
        try {
            return RequirementFactory::createOrn($orn);
        } catch (\Throwable $exception) {
            throw $this->invalidOrn($exception);
        }
    }

    private function invalidOrn(\Throwable $exception): ResourceException
    {
        return new ResourceException(
            ErrorCode::IamInvalidOrn,
            sprintf(
                'Invalid IAM policy or requirement ORN: %s',
                $exception->getMessage(),
            ),
            previous: $exception,
        );
    }
}
