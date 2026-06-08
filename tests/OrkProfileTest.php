<?php

declare(strict_types=1);

namespace Amtgard\IdpClient\Tests;

use Amtgard\IdpClient\OrkProfile;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(OrkProfile::class)]
final class OrkProfileTest extends TestCase
{
    public function testFromArrayMapsAllFields(): void
    {
        $profile = OrkProfile::fromArray([
            'mundane_id' => 1,
            'username' => 'user',
            'persona' => 'Persona',
            'suspended' => true,
            'suspended_at' => '2026-01-01',
            'suspended_until' => '2026-06-01',
            'park_id' => 5,
            'park_name' => 'Park',
            'kingdom_id' => 2,
            'kingdom_name' => 'Kingdom',
            'image' => 'img',
            'heraldry' => 'herald',
            'dues_through' => '2027-01-01',
        ]);

        $this->assertTrue($profile->suspended);
        $this->assertSame('Park', $profile->parkName);
        $this->assertSame('2027-01-01', $profile->duesThrough);
    }
}
