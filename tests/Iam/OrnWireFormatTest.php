<?php

declare(strict_types=1);

namespace Amtgard\IdpClient\Tests\Iam;

use Amtgard\IAM\ClaimFactory;
use Amtgard\IAM\Definitions\ORN\OrkClaim;
use Amtgard\IAM\OrkServices;
use Amtgard\IdpClient\ClientIam\Iam\IntegratorOrnRegistrar;
use Amtgard\IdpClient\Iam\OrnWireFormat;
use Amtgard\IdpClient\Iam\OrnWireParts;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(OrnWireFormat::class)]
#[CoversClass(OrnWireParts::class)]
final class OrnWireFormatTest extends TestCase
{
    private const CUSTOM = 'WireFormatExample';

    protected function setUp(): void
    {
        IntegratorOrnRegistrar::register(self::CUSTOM, ['tenant-id', OrkServices::Kingdom]);
    }

    protected function tearDown(): void
    {
        IntegratorOrnRegistrar::reset();
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
        IntegratorOrnRegistrar::register('Skbc', [
            OrkServices::Configuration,
            OrkServices::Game,
            OrkServices::Kingdom,
            OrkServices::Park,
        ]);

        $orn = OrnWireFormat::composeFullOrn(
            'Skbc',
            [
                OrkServices::Configuration,
                OrkServices::Game,
                OrkServices::Kingdom,
                OrkServices::Park,
            ],
            ['Configuration' => 0],
            'Officer/Approve',
        );
        $claim = ClaimFactory::createOrn($orn);

        $parts = OrnWireFormat::fromClaim($claim);

        $this->assertSame(':0::::', $parts->provisos);
        $this->assertSame('Officer/Approve', $parts->resource);
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
        IntegratorOrnRegistrar::register('Skbc', [OrkServices::Kingdom]);

        $this->expectException(\InvalidArgumentException::class);
        OrnWireFormat::composeFullOrn(
            'Skbc',
            [OrkServices::Kingdom],
            ['Unknown' => 1],
            'Editor/Write',
        );
    }

    public function testFromClaimWorksWithBuiltinOrkClaim(): void
    {
        $claim = new OrkClaim(OrkServices::ORK, 'ORK:1:7:8:9:10:ORK/AddKingdom');
        $parts = OrnWireFormat::fromClaim($claim);

        $this->assertSame('ORK', $parts->prefix);
        $this->assertSame('ORK/AddKingdom', $parts->resource);
    }
}
