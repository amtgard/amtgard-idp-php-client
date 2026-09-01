<?php

declare(strict_types=1);

namespace Amtgard\IdpClient\Tests\Iam;

use Amtgard\IAM\Catalog\ServiceCatalog;
use Amtgard\IAM\ClaimFactory;
use Amtgard\IAM\Definitions\ORN\OrkClaim;
use Amtgard\IAM\ORN\OrnSegmentLabel;
use Amtgard\IdpClient\Iam\OrnBootstrap;
use Amtgard\IdpClient\Iam\OrnWireFormat;
use Amtgard\IdpClient\Iam\OrnWireParts;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(OrnWireFormat::class)]
#[CoversClass(OrnWireParts::class)]
final class OrnWireFormatTest extends TestCase
{
    protected function setUp(): void
    {
        OrnBootstrap::register();
    }

    public function testDecomposeIdpFixture(): void
    {
        $parts = OrnWireFormat::decompose('Skbc:0::::Officer/Approve', 4);

        $this->assertSame('Skbc', $parts->prefix);
        $this->assertSame(':0::::', $parts->provisos);
        $this->assertSame('Officer/Approve', $parts->resource);
        $this->assertSame('Skbc:0::::Officer/Approve', $parts->fullOrn());
    }

    public function testRoundTripViaClaim(): void
    {
        $schema = [
            ServiceCatalog::Configuration,
            ServiceCatalog::Game,
            ServiceCatalog::Kingdom,
            ServiceCatalog::Park,
        ];

        $orn = OrnWireFormat::composeFullOrn(
            'Idp',
            $schema,
            ['Configuration' => 0],
            'IDP/EditClient',
        );
        $claim = ClaimFactory::createOrn($orn);

        $parts = OrnWireFormat::fromClaim($claim);

        $this->assertSame(':0::::', $parts->provisos);
        $this->assertSame('IDP/EditClient', $parts->resource);
        $this->assertSame($claim->buildOrn(), $parts->fullOrn());
    }

    public function testCustomLabelsDecompose(): void
    {
        $parts = OrnWireFormat::decompose('WireFormatExample:42:7:Widget/Read', 2);

        $this->assertSame(':42:7:', $parts->provisos);
        $this->assertSame('Widget/Read', $parts->resource);
    }

    public function testComposeFullOrnRejectsUnknownSegmentKeys(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        OrnWireFormat::composeFullOrn(
            'Idp',
            [ServiceCatalog::Kingdom],
            ['Unknown' => 1],
            'IDP/EditClient',
        );
    }

    public function testComposeFullOrnAcceptsOrnSegmentLabelAndNullishValues(): void
    {
        $orn = OrnWireFormat::composeFullOrn(
            'Idp',
            [
                OrnSegmentLabel::from(ServiceCatalog::Configuration),
                ServiceCatalog::Game,
                'Kingdom',
                ServiceCatalog::Park,
            ],
            [
                'Configuration' => 1,
                'Game' => null,
                'Kingdom' => '',
                'Park' => 9,
            ],
            'IDP/EditIdentity',
        );

        $this->assertSame('Idp:1:::9:IDP/EditIdentity', $orn);
    }

    public function testDecomposeRejectsMissingColon(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        OrnWireFormat::decompose('NoColon', 1);
    }

    public function testDecomposeRejectsMissingSegments(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        OrnWireFormat::decompose('Idp:0:IDP/EditClient', 4);
    }

    public function testDecomposeRejectsEmptyResource(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        OrnWireFormat::decompose('Idp:0::::', 4);
    }

    public function testFromClaimWorksWithBuiltinOrkClaim(): void
    {
        $claim = new OrkClaim(ServiceCatalog::ORK, 'ORK:1:7:8:9:10:ORK/AddKingdom');
        $parts = OrnWireFormat::fromClaim($claim);

        $this->assertSame('ORK', $parts->prefix);
        $this->assertSame('ORK/AddKingdom', $parts->resource);
    }
}
