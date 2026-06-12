<?php

declare(strict_types=1);

namespace Amtgard\IdpClient\Iam;

use Amtgard\IAM\Allowance\Policy;
use Amtgard\IAM\Requirement\Requirement;

/**
 * Evaluates IAM policy against a requirement using amtgard/ork-iam — same primitives as the IDP
 * {@see \Amtgard\IdP\Controllers\Api\ApiController::isAuthorized} endpoint.
 */
final class AuthorizationEvaluator
{
    public function __construct()
    {
        OrnBootstrap::register();
    }

    public function evaluate(Policy $policy, Requirement $requirement): AuthorizationCheck
    {
        return new AuthorizationCheck($policy->isAuthorized($requirement));
    }
}
