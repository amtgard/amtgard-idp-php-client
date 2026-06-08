<?php

declare(strict_types=1);

namespace Amtgard\IdpClient\Tests\Integration;

use Amtgard\IdpClient\ArrayEnvironment;
use Amtgard\IdpClient\Exception\ErrorCode;
use Amtgard\IdpClient\Exception\ResourceException;
use Amtgard\IdpClient\Exception\TokenExchangeException;
use Amtgard\IdpClient\IdpClient;
use Amtgard\IdpClient\IdpClientFactory;
use Amtgard\IdpClient\InMemoryOAuthFlowStateStore;
use Amtgard\IdpClient\OAuthFlowState;
use Amtgard\IdpClient\Pkce;
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
            $validated = $client->validate($profile->jwt);
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

        $profile = $client->fetchUserProfile($accessToken);
        $jwt = $client->fetchJwt($accessToken);

        $this->assertNotSame('', $jwt);
        $this->assertSame(3, substr_count($jwt, '.'), 'IDP authorization JWT should be a compact JWS');
        $this->assertNotSame('', $profile->jwt);
    }

    public function testCheckAuthorizationWithEmptyPolicy(): void
    {
        $check = $this->createClient()->checkAuthorization([], 'Idp:0:0:0:0:IDP/EditClient');

        $this->assertFalse($check->isAuthorized);
    }

    public function testCheckAuthorizationWithMalformedPolicy(): void
    {
        $http = new GuzzleClient(['http_errors' => false, 'timeout' => 10]);
        $response = $http->post($this->environment->idpBaseUrl() . '/api/is_authorized', [
            'form_params' => [
                'policy' => 'not-valid-json',
                'requirement' => 'Idp:0:0:0:0:IDP/EditClient',
            ],
        ]);

        $this->assertGreaterThanOrEqual(400, $response->getStatusCode());
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

        $check = $this->createClient()->checkAuthorization($policy, $requirement);

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
}
