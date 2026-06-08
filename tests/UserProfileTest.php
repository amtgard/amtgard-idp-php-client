<?php

declare(strict_types=1);

namespace Amtgard\IdpClient\Tests;

use Amtgard\IdpClient\Tests\Support\Fixtures;
use Amtgard\IdpClient\UserProfile;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(UserProfile::class)]
final class UserProfileTest extends TestCase
{
    public function testFromArrayWithOrkProfile(): void
    {
        $json = Fixtures::read('userinfo_with_ork.json');
        $data = json_decode($json, true, 512, JSON_THROW_ON_ERROR);

        $profile = UserProfile::fromArray($data);

        $this->assertSame(123, $profile->id);
        $this->assertSame('player@amtgard.com', $profile->email);
        $this->assertNotNull($profile->orkProfile);
        $this->assertSame(456, $profile->orkProfile->mundaneId);
        $this->assertSame('Emerald Hills', $profile->orkProfile->kingdomName);
    }

    public function testFromArrayWithoutOrkProfile(): void
    {
        $json = Fixtures::read('userinfo_without_ork.json');
        $data = json_decode($json, true, 512, JSON_THROW_ON_ERROR);

        $profile = UserProfile::fromArray($data);

        $this->assertNull($profile->orkProfile);
    }
}
