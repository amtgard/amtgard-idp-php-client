<?php

declare(strict_types=1);

namespace Amtgard\IdpClient\Tests;

use Amtgard\IdpClient\Exception\ErrorCode;
use Amtgard\IdpClient\Exception\IdpClientException;
use Amtgard\IdpClient\Exception\InvalidOAuthStateException;
use Amtgard\IdpClient\Exception\ResourceException;
use Amtgard\IdpClient\Exception\TokenExchangeException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(IdpClientException::class)]
#[CoversClass(InvalidOAuthStateException::class)]
#[CoversClass(TokenExchangeException::class)]
#[CoversClass(ResourceException::class)]
#[CoversClass(ErrorCode::class)]
final class ExceptionTest extends TestCase
{
    public function testDeveloperHintReferencesReadme(): void
    {
        $exception = new InvalidOAuthStateException(
            ErrorCode::StateMismatch,
            'state mismatch',
        );

        $this->assertStringContainsString('IDP_CLIENT_STATE_MISMATCH', $exception->developerHint());
        $this->assertStringContainsString('#error-idp_client_state_mismatch', $exception->developerHint());
    }

    public function testIdpExceptionAccessors(): void
    {
        $exception = new TokenExchangeException(
            ErrorCode::TokenInvalidClient,
            'bad client',
            'invalid_client',
            'Client authentication failed',
        );

        $this->assertSame(ErrorCode::TokenInvalidClient, $exception->errorCode());
        $this->assertSame('invalid_client', $exception->idpError());
        $this->assertSame('Client authentication failed', $exception->idpErrorDescription());
    }

    public function testErrorCodeReadmeAnchor(): void
    {
        $this->assertSame(
            '#error-idp_client_token_pkce_failed',
            ErrorCode::TokenPkceFailed->readmeAnchor(),
        );
    }
}
