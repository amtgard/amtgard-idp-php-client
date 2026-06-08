<?php

declare(strict_types=1);

namespace Amtgard\IdpClient\Tests;

use Amtgard\IdpClient\Pkce;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(Pkce::class)]
final class PkceTest extends TestCase
{
    public function testKnownVerifierProducesKnownChallenge(): void
    {
        $verifier = 'dBjftJeZ4CVP-mB92K27uhbUJU1p1r_wW1gFWFOEjXk';
        $challenge = Pkce::challengeFromVerifier($verifier);

        $this->assertSame('E9Melhoa2OwvFrEMTJguCHaoeK1t8URWbuGJSstw-cM', $challenge);
    }

    public function testBase64UrlEncodeStripsPadding(): void
    {
        $encoded = Pkce::base64UrlEncode('foo');

        $this->assertStringNotContainsString('=', $encoded);
        $this->assertStringNotContainsString('+', $encoded);
        $this->assertStringNotContainsString('/', $encoded);
    }

    public function testGenerateVerifierAndStateAreNonEmpty(): void
    {
        $this->assertNotSame('', Pkce::generateVerifier());
        $this->assertSame(32, strlen(Pkce::generateState()));
    }
}
