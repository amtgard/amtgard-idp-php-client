<?php

declare(strict_types=1);

namespace Amtgard\IdpClient\Tests\Iam;

use Amtgard\IAM\ORN\OrnClassMap;
use Amtgard\IAM\OrkServices;
use Amtgard\IdpClient\Iam\Orn\IdpClaim;
use Amtgard\IdpClient\Iam\Orn\IdpRequirement;
use Amtgard\IdpClient\Iam\OrnBootstrap;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(OrnBootstrap::class)]
final class OrnBootstrapTest extends TestCase
{
    protected function setUp(): void
    {
        OrnClassMap::reset();
        OrnBootstrap::reset();
    }

    protected function tearDown(): void
    {
        OrnClassMap::reset();
        require dirname(__DIR__, 2) . '/vendor/amtgard/ork-iam-orn-definitions/src/register.php';
        OrnBootstrap::reset();
    }

    public function testRegisterAddsIdpClaimAndRequirementClasses(): void
    {
        OrnBootstrap::register();

        $this->assertTrue(OrnClassMap::isRegistered(OrkServices::Idp));
        $this->assertTrue(OrnClassMap::isRegistered(OrkServices::Idp, asRequirement: true));
        $this->assertSame(IdpClaim::class, OrnClassMap::getClaimClass(OrkServices::Idp));
        $this->assertSame(IdpRequirement::class, OrnClassMap::getRequirementClass(OrkServices::Idp));
    }

    public function testRegisterIsIdempotentWhenOrnClassMapAlreadyHasIdp(): void
    {
        OrnClassMap::registerClaim(OrkServices::Idp, IdpClaim::class);
        OrnClassMap::registerRequirement(OrkServices::Idp, IdpRequirement::class);

        OrnBootstrap::register();
        OrnBootstrap::register();

        $this->assertSame(IdpClaim::class, OrnClassMap::getClaimClass(OrkServices::Idp));
        $this->assertSame(IdpRequirement::class, OrnClassMap::getRequirementClass(OrkServices::Idp));
    }

    public function testRegisterReturnsEarlyWhenAlreadyBootstrapped(): void
    {
        OrnBootstrap::register();

        OrnClassMap::reset();

        OrnBootstrap::register();

        $this->assertFalse(OrnClassMap::isRegistered(OrkServices::Idp));
    }

    public function testResetAllowsRegisterToRunAgain(): void
    {
        OrnBootstrap::register();
        OrnBootstrap::reset();
        OrnBootstrap::register();

        $this->assertTrue(OrnClassMap::isRegistered(OrkServices::Idp));
    }
}
