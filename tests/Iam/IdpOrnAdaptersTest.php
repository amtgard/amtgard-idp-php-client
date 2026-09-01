<?php

declare(strict_types=1);

namespace Amtgard\IdpClient\Tests\Iam;

use Amtgard\IAM\Catalog\ServiceCatalog;
use Amtgard\IdpClient\Iam\Orn\IdpClaim;
use Amtgard\IdpClient\Iam\Orn\IdpFormat;
use Amtgard\IdpClient\Iam\Orn\IdpRequirement;
use Amtgard\IdpClient\Iam\OrnBootstrap;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(IdpFormat::class)]
#[CoversClass(IdpClaim::class)]
#[CoversClass(IdpRequirement::class)]
final class IdpOrnAdaptersTest extends TestCase
{
    protected function setUp(): void
    {
        OrnBootstrap::register();
    }

    public function testIdpFormatSchemaAndResourceMap(): void
    {
        $this->assertSame([
            ServiceCatalog::Configuration,
            ServiceCatalog::Game,
            ServiceCatalog::Kingdom,
            ServiceCatalog::Park,
        ], IdpFormat::ornSegmentSchema());

        $this->assertSame(
            ['IDP' => ['EditClient', 'EditIdentity']],
            IdpFormat::getValidResourceMap(),
        );
        $this->assertSame(['EditClient', 'EditIdentity'], IdpFormat::getValidResourceMap('IDP'));
    }

    public function testIdpClaimAndRequirementShareSchema(): void
    {
        $claim = new IdpClaim(ServiceCatalog::Idp, 'Idp:0:0:0:0:IDP/EditClient');
        $requirement = new IdpRequirement(ServiceCatalog::Idp, 'Idp:0:0:0:0:IDP/EditIdentity');

        $this->assertSame(IdpFormat::ornSegmentSchema(), $claim->ornSegmentSchema());
        $this->assertSame(IdpFormat::ornSegmentSchema(), $requirement->ornSegmentSchema());
        $this->assertSame('Idp:0:0:0:0:IDP/EditClient', $claim->buildOrn());
        $this->assertSame('Idp:0:0:0:0:IDP/EditIdentity', $requirement->buildOrn());
    }
}
