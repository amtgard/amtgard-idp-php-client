<?php

declare(strict_types=1);

namespace Amtgard\IdpClient\Tests\Iam;

use Amtgard\IdpClient\Exception\ErrorCode;
use Amtgard\IdpClient\Exception\ResourceException;
use Amtgard\IdpClient\Iam\OrnParser;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(OrnParser::class)]
final class OrnParserTest extends TestCase
{
    private OrnParser $parser;

    protected function setUp(): void
    {
        $this->parser = new OrnParser();
    }

    public function testRequirementFromOrnReturnsRequirement(): void
    {
        $requirement = $this->parser->requirementFromOrn('Idp:0:0:0:0:IDP/EditClient');

        $this->assertSame('Idp:0:0:0:0:IDP/EditClient', $requirement->buildOrn());
    }

    public function testInvalidRequirementOrnThrows(): void
    {
        try {
            $this->parser->requirementFromOrn('not-an-orn');
            $this->fail('Expected ResourceException');
        } catch (ResourceException $exception) {
            $this->assertSame(ErrorCode::IamInvalidOrn, $exception->errorCode());
        }
    }

    public function testInvalidPolicyOrnThrows(): void
    {
        try {
            $this->parser->policyFromOrns(['not-an-orn']);
            $this->fail('Expected ResourceException');
        } catch (ResourceException $exception) {
            $this->assertSame(ErrorCode::IamInvalidOrn, $exception->errorCode());
        }
    }
}
