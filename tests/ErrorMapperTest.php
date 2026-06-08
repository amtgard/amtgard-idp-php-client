<?php

declare(strict_types=1);

namespace Amtgard\IdpClient\Tests;

use Amtgard\IdpClient\Exception\ErrorCode;
use Amtgard\IdpClient\Exception\ErrorMapper;
use League\OAuth2\Client\Provider\Exception\IdentityProviderException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(ErrorMapper::class)]
final class ErrorMapperTest extends TestCase
{
    public function testMapsInvalidClient(): void
    {
        $response = ['error' => 'invalid_client', 'error_description' => 'Client authentication failed'];
        $exception = new IdentityProviderException(
            json_encode($response, JSON_THROW_ON_ERROR),
            401,
            $response,
        );

        $mapped = ErrorMapper::mapTokenExchangeFailure($exception);

        $this->assertSame(ErrorCode::TokenInvalidClient, $mapped->errorCode());
        $this->assertStringContainsString('IDP_CLIENT_TOKEN_INVALID_CLIENT', $mapped->getMessage());
    }

    public function testMapsPkceFailure(): void
    {
        $response = [
            'error' => 'invalid_grant',
            'error_description' => 'Code verifier does not match code challenge',
        ];
        $exception = new IdentityProviderException(
            json_encode($response, JSON_THROW_ON_ERROR),
            400,
            $response,
        );

        $mapped = ErrorMapper::mapTokenExchangeFailure($exception);

        $this->assertSame(ErrorCode::TokenPkceFailed, $mapped->errorCode());
    }

    public function testMapsRedirectMismatch(): void
    {
        $response = [
            'error' => 'invalid_grant',
            'error_description' => 'redirect_uri mismatch',
        ];
        $exception = new IdentityProviderException(
            json_encode($response, JSON_THROW_ON_ERROR),
            400,
            $response,
        );

        $mapped = ErrorMapper::mapTokenExchangeFailure($exception);

        $this->assertSame(ErrorCode::TokenRedirectMismatch, $mapped->errorCode());
    }

    public function testDetectWafReturnsNullForEmptyBody(): void
    {
        $this->assertNull(ErrorMapper::detectWafOrHtml('   ', 500));
    }

    public function testDetects403PlainTextAsWaf(): void
    {
        $this->assertSame(
            ErrorCode::WafOrHtmlResponse,
            ErrorMapper::detectWafOrHtml('Forbidden by edge firewall', 403),
        );
    }

    public function testDetectsCloudflareHtml(): void
    {
        $body = '<!DOCTYPE html><html><body>Attention Required | Cloudflare cf-ray-abc</body></html>';

        $this->assertSame(ErrorCode::WafOrHtmlResponse, ErrorMapper::detectWafOrHtml($body, 403));
    }

    public function testMapsRefreshFailure(): void
    {
        $response = ['error' => 'invalid_grant', 'error_description' => 'refresh token expired'];
        $exception = new IdentityProviderException(
            json_encode($response, JSON_THROW_ON_ERROR),
            400,
            $response,
        );

        $mapped = ErrorMapper::mapRefreshFailure($exception);

        $this->assertSame(ErrorCode::TokenRefreshFailed, $mapped->errorCode());
    }

    public function testMapsRedirectUriMismatchErrorCode(): void
    {
        $response = ['error' => 'redirect_uri_mismatch'];
        $exception = new IdentityProviderException(
            json_encode($response, JSON_THROW_ON_ERROR),
            400,
            $response,
        );

        $mapped = ErrorMapper::mapTokenExchangeFailure($exception);

        $this->assertSame(ErrorCode::TokenRedirectMismatch, $mapped->errorCode());
    }

    public function testExtractPayloadFromResponseBodyArray(): void
    {
        $response = ['error' => 'invalid_grant', 'error_description' => 'expired code'];
        $exception = new IdentityProviderException('error', 400, $response);

        $mapped = ErrorMapper::mapTokenExchangeFailure($exception);

        $this->assertSame(ErrorCode::TokenInvalidGrant, $mapped->errorCode());
    }

    public function testExtractPayloadFromResponseBodyString(): void
    {
        $json = json_encode(['error' => 'invalid_grant'], JSON_THROW_ON_ERROR);
        $exception = new IdentityProviderException($json, 400, $json);

        $mapped = ErrorMapper::mapTokenExchangeFailure($exception);

        $this->assertSame(ErrorCode::TokenInvalidGrant, $mapped->errorCode());
    }

    public function testMapsGenericTokenExchangeFailure(): void
    {
        $exception = new IdentityProviderException('unknown', 500, ['error' => 'weird_error']);

        $mapped = ErrorMapper::mapTokenExchangeFailure($exception);

        $this->assertSame(ErrorCode::TokenExchangeFailed, $mapped->errorCode());
    }

    public function testParseOAuthErrorPayloadFromNestedJson(): void
    {
        $payload = ErrorMapper::parseOAuthErrorPayload(
            'Error: {"error":"invalid_grant","error_description":"expired"}',
        );

        $this->assertSame('invalid_grant', $payload['error']);
    }

    public function testDetectWafReturnsNullForBenignJson(): void
    {
        $this->assertNull(ErrorMapper::detectWafOrHtml('{"ok":true}', 200));
    }

    public function testExtractPayloadFallsBackWhenResponseBodyIsNotParseable(): void
    {
        $exception = new IdentityProviderException('plain oauth failure', 400, 12345);

        $mapped = ErrorMapper::mapTokenExchangeFailure($exception);

        $this->assertSame(ErrorCode::TokenExchangeFailed, $mapped->errorCode());
    }

    public function testParseOAuthErrorPayloadReturnsEmptyForPlainText(): void
    {
        $this->assertSame([], ErrorMapper::parseOAuthErrorPayload('plain failure'));
    }

    public function testBuildTokenMessageIncludesHintWhenPresent(): void
    {
        $response = [
            'error' => 'invalid_grant',
            'error_description' => 'expired',
            'hint' => 'Check redirect_uri',
        ];
        $exception = new IdentityProviderException(json_encode($response, JSON_THROW_ON_ERROR), 400, $response);

        $mapped = ErrorMapper::mapTokenExchangeFailure($exception);

        $this->assertStringContainsString('Check redirect_uri', $mapped->getMessage());
    }
}
