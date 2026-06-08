<?php

declare(strict_types=1);

namespace Amtgard\IdpClient\Exception;

use League\OAuth2\Client\Provider\Exception\IdentityProviderException;

final class ErrorMapper
{
    /**
     * @param array<string, mixed>|null $payload
     */
    public static function mapTokenExchangeFailure(
        IdentityProviderException $exception,
        ?array $payload = null,
    ): TokenExchangeException {
        $payload ??= self::extractOAuthErrorPayload($exception);
        $idpError = self::stringOrNull($payload['error'] ?? null);
        $idpDescription = self::stringOrNull($payload['error_description'] ?? $exception->getMessage());
        $hint = self::stringOrNull($payload['hint'] ?? null);

        $errorCode = self::mapOAuthTokenError($idpError, $idpDescription, $hint);

        return new TokenExchangeException(
            $errorCode,
            self::buildTokenMessage($errorCode, $idpError, $idpDescription, $hint),
            $idpError,
            $idpDescription,
            $exception,
        );
    }

    /**
     * @param array<string, mixed> $payload
     */
    public static function mapTokenErrorPayload(array $payload, bool $isRefresh = false): TokenExchangeException
    {
        $idpError = self::stringOrNull($payload['error'] ?? null);
        $idpDescription = self::stringOrNull($payload['error_description'] ?? null);
        $hint = self::stringOrNull($payload['hint'] ?? null);

        if ($isRefresh) {
            return new TokenExchangeException(
                ErrorCode::TokenRefreshFailed,
                sprintf(
                    'Refresh token exchange failed (%s). %s',
                    $idpError ?? 'unknown_error',
                    self::developerHint(ErrorCode::TokenRefreshFailed),
                ),
                $idpError,
                $idpDescription,
            );
        }

        $errorCode = self::mapOAuthTokenError($idpError, $idpDescription, $hint);

        return new TokenExchangeException(
            $errorCode,
            self::buildTokenMessage($errorCode, $idpError, $idpDescription, $hint),
            $idpError,
            $idpDescription,
        );
    }

    public static function mapRefreshFailure(IdentityProviderException $exception): TokenExchangeException
    {
        $payload = self::extractOAuthErrorPayload($exception);
        $idpError = self::stringOrNull($payload['error'] ?? null);
        $idpDescription = self::stringOrNull($payload['error_description'] ?? $exception->getMessage());

        return new TokenExchangeException(
            ErrorCode::TokenRefreshFailed,
            sprintf(
                'Refresh token exchange failed (%s). %s',
                $idpError ?? 'unknown_error',
                self::developerHint(ErrorCode::TokenRefreshFailed),
            ),
            $idpError,
            $idpDescription,
            $exception,
        );
    }

    public static function detectWafOrHtml(string $body, int $statusCode): ?ErrorCode
    {
        $trimmed = ltrim($body);

        if ($trimmed === '') {
            return null;
        }

        if (
            str_starts_with($trimmed, '<!DOCTYPE')
            || str_starts_with($trimmed, '<html')
            || str_contains($body, 'cf-ray')
            || str_contains($body, 'Cloudflare')
            || str_contains($body, 'Attention Required')
        ) {
            return ErrorCode::WafOrHtmlResponse;
        }

        if ($statusCode === 403 && !self::looksLikeJson($trimmed)) {
            return ErrorCode::WafOrHtmlResponse;
        }

        return null;
    }

    /**
     * @return array<string, mixed>
     */
    public static function extractOAuthErrorPayload(IdentityProviderException $exception): array
    {
        $body = $exception->getResponseBody();
        if (is_array($body)) {
            return $body;
        }

        if (is_string($body)) {
            $decoded = json_decode($body, true);
            if (is_array($decoded)) {
                return $decoded;
            }
        }

        return self::parseOAuthErrorPayload($exception->getMessage());
    }

    /**
     * @return array<string, mixed>
     */
    public static function parseOAuthErrorPayload(string $message): array
    {
        $decoded = json_decode($message, true);
        if (is_array($decoded)) {
            return $decoded;
        }

        if (preg_match('/\{.*\}/s', $message, $matches) === 1) {
            $nested = json_decode($matches[0], true);
            if (is_array($nested)) {
                return $nested;
            }
        }

        return [];
    }

    private static function mapOAuthTokenError(
        ?string $idpError,
        ?string $description,
        ?string $hint,
    ): ErrorCode {
        $haystack = strtolower(implode(' ', array_filter([$idpError, $description, $hint])));

        return match ($idpError) {
            'invalid_client' => ErrorCode::TokenInvalidClient,
            'invalid_grant' => self::mapInvalidGrant($haystack),
            'redirect_uri_mismatch' => ErrorCode::TokenRedirectMismatch,
            default => ErrorCode::TokenExchangeFailed,
        };
    }

    private static function mapInvalidGrant(string $haystack): ErrorCode
    {
        if (
            str_contains($haystack, 'code_verifier')
            || str_contains($haystack, 'code challenge')
            || str_contains($haystack, 'pkce')
        ) {
            return ErrorCode::TokenPkceFailed;
        }

        if (str_contains($haystack, 'redirect_uri')) {
            return ErrorCode::TokenRedirectMismatch;
        }

        return ErrorCode::TokenInvalidGrant;
    }

    private static function buildTokenMessage(
        ErrorCode $errorCode,
        ?string $idpError,
        ?string $idpDescription,
        ?string $hint,
    ): string {
        $parts = array_filter([
            sprintf('Token exchange failed [%s]', $errorCode->value),
            $idpError !== null ? "IDP error: {$idpError}" : null,
            $idpDescription !== null ? "IDP description: {$idpDescription}" : null,
            $hint !== null ? "IDP hint: {$hint}" : null,
            self::developerHint($errorCode),
        ]);

        return implode(' ', $parts);
    }

    private static function developerHint(ErrorCode $errorCode): string
    {
        return sprintf('See README %s for fix instructions.', $errorCode->readmeAnchor());
    }

    private static function stringOrNull(mixed $value): ?string
    {
        return is_string($value) && $value !== '' ? $value : null;
    }

    private static function looksLikeJson(string $body): bool
    {
        return str_starts_with($body, '{') || str_starts_with($body, '[');
    }
}
