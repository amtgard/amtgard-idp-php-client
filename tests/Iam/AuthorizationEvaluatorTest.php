<?php

declare(strict_types=1);

namespace Amtgard\IdpClient\Tests\Iam;

use Amtgard\IdpClient\Iam\AuthorizationEvaluator;
use Amtgard\IdpClient\Iam\OrnParser;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(AuthorizationEvaluator::class)]
final class AuthorizationEvaluatorTest extends TestCase
{
    private AuthorizationEvaluator $evaluator;
    private OrnParser $ornParser;

    protected function setUp(): void
    {
        $this->evaluator = new AuthorizationEvaluator();
        $this->ornParser = new OrnParser();
    }

    public function testEmptyPolicyIsNotAuthorized(): void
    {
        $check = $this->evaluator->evaluate(
            $this->ornParser->policyFromOrns([]),
            $this->ornParser->requirementFromOrn('Idp:0:0:0:0:IDP/EditClient'),
        );

        $this->assertFalse($check->isAuthorized);
    }

    public function testMatchingIdpClaimAuthorizes(): void
    {
        $check = $this->evaluator->evaluate(
            $this->ornParser->policyFromOrns(['Idp:0:0:0:0:IDP/EditClient']),
            $this->ornParser->requirementFromOrn('Idp:0:0:0:0:IDP/EditClient'),
        );

        $this->assertTrue($check->isAuthorized);
    }

    public function testMismatchedRequirementIsNotAuthorized(): void
    {
        $check = $this->evaluator->evaluate(
            $this->ornParser->policyFromOrns(['Idp:0:0:0:0:IDP/EditClient']),
            $this->ornParser->requirementFromOrn('Idp:0:0:0:0:IDP/EditIdentity'),
        );

        $this->assertFalse($check->isAuthorized);
    }
}
