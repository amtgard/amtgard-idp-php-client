<?php

declare(strict_types=1);

namespace Amtgard\IdpClient\Tests\ClientIam\Iam;

use Amtgard\IAM\Catalog\ServiceCatalog;
use Amtgard\IAM\ClaimFactory;
use Amtgard\IAM\ORN\OrnClassMap;
use Amtgard\IdpClient\ClientIam\Iam\IntegratorClaim;
use Amtgard\IdpClient\ClientIam\Iam\IntegratorFormatRegistry;
use Amtgard\IdpClient\ClientIam\Iam\IntegratorOrnRegistrar;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(IntegratorClaim::class)]
#[CoversClass(IntegratorFormatRegistry::class)]
#[CoversClass(IntegratorOrnRegistrar::class)]
final class IntegratorAdaptersTest extends TestCase
{
    protected function tearDown(): void
    {
        IntegratorOrnRegistrar::reset();
    }

    public function testFormatRegistryRoundTripAndHas(): void
    {
        $format = [ServiceCatalog::Configuration, 'custom-slot'];
        IntegratorFormatRegistry::register('Skbc', $format);

        $this->assertTrue(IntegratorFormatRegistry::has('Skbc'));
        $this->assertFalse(IntegratorFormatRegistry::has('missing'));
        $this->assertSame('Skbc', IntegratorFormatRegistry::currentService());
        $this->assertSame($format, IntegratorFormatRegistry::get('Skbc'));
    }

    public function testFormatRegistryGetThrowsWhenUnregistered(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('No integrator service format registered for "missing".');
        IntegratorFormatRegistry::get('missing');
    }

    public function testRegistrarSkipsIdpPrefixCaseInsensitive(): void
    {
        IntegratorOrnRegistrar::register('idp', [ServiceCatalog::Configuration]);

        $this->assertTrue(IntegratorFormatRegistry::has('idp'));
        $this->assertFalse(OrnClassMap::isRegistered('idp'));
    }

    public function testRegistrarSkipsBuiltInCatalogPrefix(): void
    {
        IntegratorOrnRegistrar::register(ServiceCatalog::Documents->value, [ServiceCatalog::Configuration]);

        $this->assertTrue(IntegratorFormatRegistry::has(ServiceCatalog::Documents->value));
    }

    public function testRegistrarRegistersCustomClaimClass(): void
    {
        $prefix = 'M4CustomSvc';
        IntegratorOrnRegistrar::register($prefix, [ServiceCatalog::Configuration, ServiceCatalog::Kingdom]);

        $this->assertTrue(OrnClassMap::isRegistered($prefix));

        $claim = ClaimFactory::createOrn($prefix . ':0:123:Editor/Write');
        $this->assertInstanceOf(IntegratorClaim::class, $claim);
        $this->assertSame(
            [ServiceCatalog::Configuration, ServiceCatalog::Kingdom],
            $claim->ornSegmentSchema(),
        );
        $this->assertSame($prefix . ':0:123:Editor/Write', $claim->buildOrn());
    }

    public function testRegistrarSkipsAlreadyRegisteredPrefix(): void
    {
        $prefix = 'M4AlreadyReg';
        OrnClassMap::registerClaim($prefix, IntegratorClaim::class);

        IntegratorOrnRegistrar::register($prefix, [ServiceCatalog::Configuration]);

        $this->assertTrue(IntegratorFormatRegistry::has($prefix));
        $this->assertTrue(OrnClassMap::isRegistered($prefix));
    }

    public function testIntegratorClaimResourceMapAllowsWildcard(): void
    {
        IntegratorFormatRegistry::register('M4ResMap', [ServiceCatalog::Configuration]);
        $claim = new IntegratorClaim('M4ResMap', 'M4ResMap:0:Editor/Write');

        $method = new \ReflectionMethod(IntegratorClaim::class, 'getResourceMap');
        $this->assertSame(['*' => ['*']], $method->invoke($claim, null));

        $valid = new \ReflectionMethod(IntegratorClaim::class, 'validResource');
        $this->assertTrue($valid->invoke($claim, $claim->getResource()));
    }

    public function testIntegratorClaimFallsBackToPrefixWhenCurrentServiceCleared(): void
    {
        $prefix = 'M4FallbackSvc';
        IntegratorFormatRegistry::register($prefix, [ServiceCatalog::Configuration]);
        OrnClassMap::registerClaim($prefix, IntegratorClaim::class);

        $claim = new IntegratorClaim($prefix, $prefix . ':0:Editor/Write');

        // Simulate a later register/reset that clears currentService but leaves formats.
        IntegratorFormatRegistry::register($prefix, [ServiceCatalog::Configuration]);
        $ref = new \ReflectionClass(IntegratorFormatRegistry::class);
        $prop = $ref->getProperty('currentService');
        $prop->setValue(null, null);

        $this->assertSame([ServiceCatalog::Configuration], $claim->ornSegmentSchema());
    }
}
