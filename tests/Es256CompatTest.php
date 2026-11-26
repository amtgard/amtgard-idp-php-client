<?php

declare(strict_types=1);

namespace Amtgard\IdpClient\Tests;

use Amtgard\IdpClient\Client\IdpClient;
use Amtgard\IdpClient\OAuth\InMemoryOAuthFlowStateStore;
use Amtgard\IdpClient\OAuth\TokenSet;
use Amtgard\IdpClient\Resource\AuthenticatedSession;
use Amtgard\IdpClient\Resource\UserProfile;
use Amtgard\IdpClient\Tests\Support\Fixtures;
use Amtgard\IdpClient\Tests\Support\MockPsr18Client;
use Amtgard\IdpClient\Tests\Support\TestEnvironment;
use Nyholm\Psr7\Factory\Psr17Factory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * ES256/RS256 authorization-JWT passthrough tests.
 *
 * The library treats authorization JWTs as opaque strings and never verifies a
 * signature, so IDP-issued ES256 tokens must flow through exactly like RS256
 * ones. The fixtures are real JWS signed with throwaway test-only keys
 * (tests/Fixtures/es256-test-key.pem and rs256-test-key.pem); the keys exist
 * only so the fixtures are regenerable and are never used by the library.
 */
#[CoversClass(IdpClient::class)]
final class Es256CompatTest extends TestCase
{
    private Psr17Factory $psr17;

    private InMemoryOAuthFlowStateStore $flowState;

    protected function setUp(): void
    {
        $this->psr17 = new Psr17Factory();
        $this->flowState = new InMemoryOAuthFlowStateStore();
    }

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function authorizationJwtProvider(): array
    {
        $es256 = trim(Fixtures::read('es256-authorization-jwt.txt'));
        $rs256 = trim(Fixtures::read('rs256-authorization-jwt.txt'));

        return [
            'es256' => [$es256, 'ES256'],
            'rs256' => [$rs256, 'RS256'],
            // Same 3-part shape with an unknown alg: the library does no alg
            // allowlisting, so this must pass through untouched. The signature
            // no longer verifies — which is fine, the library never verifies.
            'ES999' => [self::withHeaderAlg($es256, 'ES999'), 'ES999'],
        ];
    }

    #[DataProvider('authorizationJwtProvider')]
    public function testFetchJwtReturnsJwsAccessTokenWithoutHttpCall(string $authorizationJwt, string $expectedAlg): void
    {
        $http = new MockPsr18Client();
        $client = $this->createClient(http: $http);

        $jwt = $client->fetchJwt($authorizationJwt);

        $this->assertSame($authorizationJwt, $jwt);
        $this->assertCount(0, $http->requests);
        $this->assertSame(2, substr_count($jwt, '.'));
        $this->assertSame($expectedAlg, self::decodeJwtHeaderAlg($jwt));
    }

    #[DataProvider('authorizationJwtProvider')]
    public function testFetchUserProfileGoesStraightToUserinfo(string $authorizationJwt, string $expectedAlg): void
    {
        $http = new MockPsr18Client();
        $http->enqueue(
            $this->psr17->createResponse(200)->withBody(
                $this->psr17->createStream(Fixtures::read('userinfo_without_ork.json')),
            ),
        );

        $client = $this->createClient(http: $http);
        $profile = $client->fetchUserProfile($authorizationJwt);

        $this->assertCount(1, $http->requests);
        $this->assertStringEndsWith('/resources/userinfo', (string) $http->requests[0]->getUri());
        $this->assertSame('Bearer ' . $authorizationJwt, $http->requests[0]->getHeaderLine('Authorization'));
        $this->assertSame(42, $profile->id);
        $this->assertSame('new@amtgard.com', $profile->email);
        $this->assertSame($expectedAlg, self::decodeJwtHeaderAlg($authorizationJwt));
    }

    #[DataProvider('authorizationJwtProvider')]
    public function testValidateUsesSameBearerOnUserinfoAndValidate(string $authorizationJwt, string $expectedAlg): void
    {
        $http = new MockPsr18Client();
        $http->enqueue(
            $this->psr17->createResponse(200)
                ->withHeader('Set-Cookie', 'PHPSESSID=abc; Path=/')
                ->withBody($this->psr17->createStream(Fixtures::read('userinfo_without_ork.json'))),
        );
        $http->enqueue(
            $this->psr17->createResponse(200)->withBody(
                $this->psr17->createStream(Fixtures::read('validate.json')),
            ),
        );

        $client = $this->createClient(http: $http);
        $session = $client->validate($authorizationJwt);

        $this->assertCount(2, $http->requests);
        $this->assertStringEndsWith('/resources/userinfo', (string) $http->requests[0]->getUri());
        $this->assertSame('Bearer ' . $authorizationJwt, $http->requests[0]->getHeaderLine('Authorization'));
        $this->assertStringEndsWith('/resources/validate', (string) $http->requests[1]->getUri());
        $this->assertSame('Bearer ' . $authorizationJwt, $http->requests[1]->getHeaderLine('Authorization'));
        $this->assertSame(42, $session->id);
        $this->assertSame($expectedAlg, self::decodeJwtHeaderAlg($authorizationJwt));
    }

    #[DataProvider('authorizationJwtProvider')]
    public function testFetchJwtWithOpaqueTokenCallsJwtEndpoint(string $authorizationJwt, string $expectedAlg): void
    {
        $http = new MockPsr18Client();
        $http->enqueue(
            $this->psr17->createResponse(200)->withBody(
                $this->psr17->createStream(
                    json_encode(['jwt' => $authorizationJwt], JSON_THROW_ON_ERROR),
                ),
            ),
        );

        $client = $this->createClient(http: $http);
        $jwt = $client->fetchJwt('opaque-access-token');

        $this->assertSame($authorizationJwt, $jwt);
        $this->assertCount(1, $http->requests);
        $this->assertStringEndsWith('/resources/jwt', (string) $http->requests[0]->getUri());
        $this->assertSame('Bearer opaque-access-token', $http->requests[0]->getHeaderLine('Authorization'));
        $this->assertSame($expectedAlg, self::decodeJwtHeaderAlg($jwt));
    }

    #[DataProvider('authorizationJwtProvider')]
    public function testAuthorizationJwtMethodsPassJwtThrough(string $authorizationJwt, string $expectedAlg): void
    {
        $http = new MockPsr18Client();
        $http->enqueue(
            $this->psr17->createResponse(200)->withBody(
                $this->psr17->createStream(Fixtures::read('userinfo_with_ork.json')),
            ),
        );
        $http->enqueue(
            $this->psr17->createResponse(200)->withBody(
                $this->psr17->createStream(Fixtures::read('validate.json')),
            ),
        );

        $client = $this->createClient(http: $http);
        $profile = $client->fetchUserProfileWithAuthorizationJwt($authorizationJwt);
        $session = $client->validateWithAuthorizationJwt($authorizationJwt);

        $this->assertCount(2, $http->requests);
        $this->assertStringEndsWith('/resources/userinfo', (string) $http->requests[0]->getUri());
        $this->assertSame('Bearer ' . $authorizationJwt, $http->requests[0]->getHeaderLine('Authorization'));
        $this->assertStringEndsWith('/resources/validate', (string) $http->requests[1]->getUri());
        $this->assertSame('Bearer ' . $authorizationJwt, $http->requests[1]->getHeaderLine('Authorization'));
        $this->assertSame('player@amtgard.com', $profile->email);
        $this->assertSame(42, $session->id);
        $this->assertSame($expectedAlg, self::decodeJwtHeaderAlg($authorizationJwt));
    }

    #[DataProvider('authorizationJwtProvider')]
    public function testSessionMethodsWithJwsAccessToken(string $authorizationJwt, string $expectedAlg): void
    {
        $http = new MockPsr18Client();
        $client = $this->createClient(http: $http);
        $session = new AuthenticatedSession(
            new TokenSet($authorizationJwt),
            new UserProfile(1, 'old@example.com', 'old.jwt'),
        );

        [$jwt, $afterJwt] = $client->fetchJwtForSession($session);

        $this->assertSame($authorizationJwt, $jwt);
        $this->assertCount(0, $http->requests);

        $http->enqueue(
            $this->psr17->createResponse(200)
                ->withHeader('Set-Cookie', 'PHPSESSID=from-idp; Path=/')
                ->withBody($this->psr17->createStream(Fixtures::read('userinfo_without_ork.json'))),
        );

        [$profile, $updated] = $client->fetchUserProfileForSession($afterJwt);

        $this->assertCount(1, $http->requests);
        $this->assertStringEndsWith('/resources/userinfo', (string) $http->requests[0]->getUri());
        $this->assertSame('Bearer ' . $authorizationJwt, $http->requests[0]->getHeaderLine('Authorization'));
        $this->assertSame(42, $profile->id);
        $this->assertSame(42, $updated->profile->id);
        $this->assertSame('PHPSESSID=from-idp', $updated->idpCookies);
        $this->assertSame($expectedAlg, self::decodeJwtHeaderAlg($authorizationJwt));
    }

    #[DataProvider('authorizationJwtProvider')]
    public function testUnknownAlgJwtPassesThroughUntouched(string $authorizationJwt, string $expectedAlg): void
    {
        $http = new MockPsr18Client();
        $client = $this->createClient(http: $http);

        $jwt = $client->fetchJwt($authorizationJwt);

        $this->assertSame($authorizationJwt, $jwt);
        $this->assertSame(2, substr_count($jwt, '.'));
        // Locks in the no-alg-allowlist contract: the ES999 row is the
        // meaningful one here; the ES256/RS256 rows prove the same path.
        $this->assertSame($expectedAlg, self::decodeJwtHeaderAlg($jwt));
    }

    private function createClient(?MockPsr18Client $http = null): IdpClient
    {
        $env = TestEnvironment::create();
        $http ??= new MockPsr18Client();

        return new IdpClient($env, $this->flowState, $http, $this->psr17, $this->psr17);
    }

    /**
     * Decode part 0 (the JOSE header) of a compact JWS and return its alg.
     * Test-side parsing only; the library never parses tokens.
     */
    private static function decodeJwtHeaderAlg(string $jwt): string
    {
        $segments = explode('.', $jwt);
        $decoded = base64_decode(strtr($segments[0], '-_', '+/'), true);
        $header = json_decode($decoded === false ? '' : $decoded, true);

        if (!is_array($header) || !isset($header['alg']) || !is_string($header['alg'])) {
            throw new \RuntimeException('JWT header has no string alg.');
        }

        return $header['alg'];
    }

    /**
     * Rebuild a compact JWS with a replaced JOSE header alg. Used only to
     * derive the unknown-alg provider row; the signature then no longer
     * verifies, which is fine — the library never verifies a signature.
     */
    private static function withHeaderAlg(string $jwt, string $alg): string
    {
        $segments = explode('.', $jwt);
        $header = json_encode(['alg' => $alg, 'typ' => 'JWT', 'kid' => 'es999-test'], JSON_THROW_ON_ERROR);
        $encoded = rtrim(strtr(base64_encode($header), '+/', '-_'), '=');

        return $encoded . '.' . ($segments[1] ?? '') . '.' . ($segments[2] ?? '');
    }
}
