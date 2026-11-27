<?php

declare(strict_types=1);

namespace Amtgard\IdpClient\Tests\Integration;

use Amtgard\IdpClient\Config\ArrayEnvironment;
use Amtgard\IdpClient\Exception\ErrorCode;
use Amtgard\IdpClient\Exception\ResourceException;
use Amtgard\IdpClient\Exception\TokenExchangeException;
use Amtgard\IdpClient\Client\IdpClient;
use Amtgard\IdpClient\Config\IdpClientFactory;
use Amtgard\IdpClient\OAuth\InMemoryOAuthFlowStateStore;
use Amtgard\IdpClient\OAuth\OAuthFlowState;
use Amtgard\IdpClient\OAuth\Pkce;
use GuzzleHttp\Client as GuzzleClient;
use Nyholm\Psr7\ServerRequest;
use PHPUnit\Framework\TestCase;

/**
 * Integration tests against the production Amtgard IDP.
 *
 * Enable with: IDP_INTEGRATION=1 composer test -- --testsuite Integration
 *
 * Environment:
 * - IDP_BASE_URL (default https://idp.amtgard.com)
 * - IDP_CLIENT_ID (default test_phpleague_oauth_client)
 * - IDP_CLIENT_SECRET (default secret)
 * - IDP_REDIRECT_URI (default https://your-app.com/callback)
 * - IDP_INTEGRATION_ACCESS_TOKEN (optional — enables bearer-authenticated resource happy paths)
 * - IDP_INTEGRATION_POLICY (optional JSON array — enables authorized checkAuthorization happy path)
 * - IDP_INTEGRATION_REQUIREMENT (optional — requirement string paired with IDP_INTEGRATION_POLICY)
 */
final class IdpIntegrationTest extends TestCase
{
    private const DEFAULT_IDP_BASE_URL = 'https://idp.amtgard.com';

    private ArrayEnvironment $environment;
    private InMemoryOAuthFlowStateStore $flowState;

    protected function setUp(): void
    {
        if (!filter_var(getenv('IDP_INTEGRATION') ?: '', FILTER_VALIDATE_BOOL)) {
            $this->markTestSkipped('Set IDP_INTEGRATION=1 to run integration tests against a live IDP.');
        }

        $this->environment = new ArrayEnvironment(
            rtrim(getenv('IDP_BASE_URL') ?: self::DEFAULT_IDP_BASE_URL, '/'),
            getenv('IDP_CLIENT_ID') ?: 'test_phpleague_oauth_client',
            getenv('IDP_CLIENT_SECRET') ?: 'secret',
            getenv('IDP_REDIRECT_URI') ?: 'https://your-app.com/callback',
        );
        $this->flowState = new InMemoryOAuthFlowStateStore();
    }

    public function testIdpIsReachable(): void
    {
        $http = new GuzzleClient(['http_errors' => false, 'timeout' => 10]);
        $response = $http->get($this->environment->idpBaseUrl() . '/oauth/authorize', [
            'allow_redirects' => false,
        ]);

        $this->assertLessThan(
            500,
            $response->getStatusCode(),
            'IDP must be reachable at ' . $this->environment->idpBaseUrl(),
        );
    }

    public function testTokenExchangeWithInvalidCodeReturnsMappedError(): void
    {
        $client = $this->createClient();

        $state = Pkce::generateState();
        $this->flowState->put(new OAuthFlowState($state, Pkce::generateVerifier()));

        $request = (new ServerRequest('GET', '/oauth/callback'))
            ->withQueryParams(['code' => 'invalid-code-for-integration-test', 'state' => $state]);

        try {
            $client->completeAuthorization($request);
            $this->fail('Expected TokenExchangeException for invalid authorization code');
        } catch (TokenExchangeException $exception) {
            $this->assertContains(
                $exception->errorCode(),
                [
                    ErrorCode::TokenInvalidGrant,
                    ErrorCode::TokenPkceFailed,
                    ErrorCode::TokenExchangeFailed,
                ],
            );
            $this->assertStringContainsString('README', $exception->getMessage());
        }
    }

    public function testUserinfoRejectsInvalidAccessToken(): void
    {
        $this->expectResourceUnauthorized(fn (IdpClient $client) => $client->fetchUserProfile('definitely-not-a-valid-access-token'));
    }

    public function testValidateRejectsInvalidAccessToken(): void
    {
        $this->expectResourceUnauthorized(fn (IdpClient $client) => $client->validate('definitely-not-a-valid-access-token'));
    }

    public function testFetchJwtRejectsInvalidAccessToken(): void
    {
        $this->expectResourceUnauthorized(fn (IdpClient $client) => $client->fetchJwt('definitely-not-a-valid-access-token'));
    }

    public function testFetchUserProfileWithValidAccessTokenWhenConfigured(): void
    {
        $client = $this->createClient();
        $accessToken = $this->requireAccessToken();

        $profile = $client->fetchUserProfile($accessToken);

        $this->assertGreaterThan(0, $profile->id);
        $this->assertNotSame('', $profile->email);
        $this->assertNotSame('', $profile->jwt);
    }

    public function testValidateWithAuthorizationJwtWhenConfigured(): void
    {
        $client = $this->createClient();
        $accessToken = $this->requireAccessToken();

        $profile = $client->fetchUserProfile($accessToken);

        try {
            $validated = $client->validateWithAuthorizationJwt($profile->jwt);
        } catch (ResourceException $exception) {
            if ($exception->errorCode() === ErrorCode::ResourceUnauthorized) {
                $this->markTestSkipped(
                    '/resources/validate requires an IDP authorization JWT plus an active IDP browser session; '
                    . 'stateless OAuth access-token tests cannot satisfy the session check.',
                );
            }

            throw $exception;
        }

        $this->assertGreaterThan(0, $validated->id);
        $this->assertSame($profile->email, $validated->email);
        $this->assertNotSame('', $validated->jwt);
    }

    public function testFetchJwtWithValidAccessTokenWhenConfigured(): void
    {
        $client = $this->createClient();
        $accessToken = $this->requireAccessToken();

        $client->fetchUserProfile($accessToken);
        $jwt = $client->fetchJwt($accessToken);

        $this->assertNotSame('', $jwt);
        $this->assertSame(2, substr_count($jwt, '.'), 'IDP authorization JWT should be a compact JWS');
    }

    /**
     * Discovery-gated ES256 round-trip. Skips while the live IDP is RS256-only
     * (no EC key published), so the test is green before cutover and doubles
     * as a cutover detector once ES256 is advertised.
     */
    public function testFetchJwtReturnsEs256AuthorizationJwtWhenIdpAdvertisesEs256(): void
    {
        $client = $this->createClient();

        if (!$this->idpAdvertisesEs256()) {
            $this->markTestSkipped('IDP does not advertise ES256 (no EC key in JWKS, no ES256 in discovery document).');
        }

        $accessToken = $this->requireAccessToken();

        $jwt = $client->fetchJwt($accessToken);

        $this->assertNotSame('', $jwt);
        $this->assertSame(2, substr_count($jwt, '.'), 'IDP authorization JWT should be a compact JWS');
        $this->assertSame('ES256', self::decodeJwtHeaderAlg($jwt));

        $profile = $client->fetchUserProfile($accessToken);

        $this->assertNotSame('', $profile->jwt);
        $this->assertSame(2, substr_count($profile->jwt, '.'), 'Profile JWT should be a compact JWS');
    }

    public function testCheckAuthorizationWithEmptyPolicy(): void
    {
        $client = $this->createClient();
        $check = $client->checkAuthorization(
            $client->policyFromOrns([]),
            $client->requirementFromOrn('Idp:0:0:0:0:IDP/EditClient'),
        );

        $this->assertFalse($check->isAuthorized);
    }

    public function testCheckAuthorizationWithMalformedPolicyOrn(): void
    {
        $client = $this->createClient();

        try {
            $client->policyFromOrns(['not-an-orn']);
            $this->fail('Expected ResourceException for malformed policy ORN');
        } catch (ResourceException $exception) {
            $this->assertSame(ErrorCode::IamInvalidOrn, $exception->errorCode());
        }
    }

    public function testCheckAuthorizationWithConfiguredPolicyWhenAuthorized(): void
    {
        $policyJson = getenv('IDP_INTEGRATION_POLICY') ?: '';
        $requirement = getenv('IDP_INTEGRATION_REQUIREMENT') ?: '';

        if ($policyJson === '' || $requirement === '') {
            $this->markTestSkipped(
                'Set IDP_INTEGRATION_POLICY (JSON array) and IDP_INTEGRATION_REQUIREMENT to test authorized policy evaluation.',
            );
        }

        try {
            $policy = json_decode($policyJson, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $exception) {
            $this->fail('IDP_INTEGRATION_POLICY must be valid JSON: ' . $exception->getMessage());
        }

        if (!is_array($policy)) {
            $this->fail('IDP_INTEGRATION_POLICY must decode to a JSON array.');
        }

        $orns = [];
        foreach ($policy as $orn) {
            if (!is_string($orn)) {
                $this->fail('IDP_INTEGRATION_POLICY must be a JSON array of ORN strings.');
            }
            $orns[] = $orn;
        }

        $client = $this->createClient();
        $check = $client->checkAuthorization(
            $client->policyFromOrns($orns),
            $client->requirementFromOrn($requirement),
        );

        $this->assertTrue($check->isAuthorized);
    }

    /**
     * @param callable(IdpClient): mixed $action
     */
    private function expectResourceUnauthorized(callable $action): void
    {
        try {
            $action($this->createClient());
            $this->fail('Expected ResourceException for invalid bearer token');
        } catch (ResourceException $exception) {
            $this->assertSame(ErrorCode::ResourceUnauthorized, $exception->errorCode());
        }
    }

    private function createClient(): IdpClient
    {
        return IdpClientFactory::fromEnvironment($this->environment, $this->flowState);
    }

    private function requireAccessToken(): string
    {
        $accessToken = getenv('IDP_INTEGRATION_ACCESS_TOKEN') ?: '';
        if ($accessToken === '') {
            $this->markTestSkipped(
                'Set IDP_INTEGRATION_ACCESS_TOKEN to test successful /resources/userinfo, /resources/jwt, and /resources/validate calls.',
            );
        }

        return $accessToken;
    }

    /**
     * True when the live IDP advertises ES256 signing. The JWKS signal is
     * preferred: an EC key in the published key set proves an EC key is
     * actually published. Falls back to the discovery document's
     * id_token_signing_alg_values_supported list. An unparseable probe
     * response (non-200, non-JSON — e.g. a bot-challenge page) is treated
     * as "not advertising ES256", so the test skips instead of failing.
     */
    private function idpAdvertisesEs256(): bool
    {
        $http = new GuzzleClient(['http_errors' => false, 'timeout' => 10]);

        $jwks = $this->fetchJsonDocument($http, $this->environment->idpBaseUrl() . '/.well-known/jwks.json');
        if (is_array($jwks)) {
            $keys = $jwks['keys'] ?? null;
            if (is_array($keys)) {
                foreach ($keys as $key) {
                    if (is_array($key) && ($key['kty'] ?? null) === 'EC') {
                        return true;
                    }
                }
            }
        }

        $discovery = $this->fetchJsonDocument($http, $this->environment->idpBaseUrl() . '/.well-known/openid-configuration');
        if (is_array($discovery)) {
            $algs = $discovery['id_token_signing_alg_values_supported'] ?? null;
            if (is_array($algs)) {
                foreach ($algs as $alg) {
                    if ($alg === 'ES256') {
                        return true;
                    }
                }
            }
        }

        return false;
    }

    /**
     * @return array<mixed>|null Decoded JSON document, or null when the
     *                           response is not a 200 JSON document.
     */
    private function fetchJsonDocument(GuzzleClient $http, string $url): ?array
    {
        try {
            $response = $http->get($url);
        } catch (\Throwable) {
            return null;
        }

        if ($response->getStatusCode() !== 200) {
            return null;
        }

        try {
            $decoded = json_decode((string) $response->getBody(), true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return null;
        }

        return is_array($decoded) ? $decoded : null;
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
}
