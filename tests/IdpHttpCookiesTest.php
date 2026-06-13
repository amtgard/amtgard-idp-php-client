<?php

declare(strict_types=1);

namespace Amtgard\IdpClient\Tests;

use Amtgard\IdpClient\Resource\Http\IdpHttpCookies;
use Nyholm\Psr7\Response;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(IdpHttpCookies::class)]
final class IdpHttpCookiesTest extends TestCase
{
    public function testRoundTripsCookieHeader(): void
    {
        $jar = IdpHttpCookies::fromHeader('PHPSESSID=abc123; other=value');
        $response = new Response(200, ['Set-Cookie' => ['PHPSESSID=def456; Path=/; HttpOnly']]);

        $jar->absorbFromResponse($response);

        $this->assertSame('PHPSESSID=def456; other=value', $jar->toHeader());
    }

    public function testAbsorbMultipleSetCookieHeaders(): void
    {
        $jar = new IdpHttpCookies();
        $jar->absorbSetCookieHeaders([
            'PHPSESSID=one; Path=/',
            'other=two; Secure',
        ]);

        $this->assertTrue($jar->hasCookies());
        $this->assertSame('PHPSESSID=one; other=two', $jar->toHeader());
    }
}
