<?php

declare(strict_types=1);

namespace Amtgard\IdpClient\Exception;

enum ErrorCode: string
{
    case FlowStateMissing = 'IDP_CLIENT_FLOW_STATE_MISSING';
    case StateParamMissing = 'IDP_CLIENT_STATE_PARAM_MISSING';
    case StateMismatch = 'IDP_CLIENT_STATE_MISMATCH';
    case AuthCodeMissing = 'IDP_CLIENT_AUTH_CODE_MISSING';
    case OAuthCallbackError = 'IDP_CLIENT_OAUTH_CALLBACK_ERROR';
    case TokenInvalidGrant = 'IDP_CLIENT_TOKEN_INVALID_GRANT';
    case TokenInvalidClient = 'IDP_CLIENT_TOKEN_INVALID_CLIENT';
    case TokenRedirectMismatch = 'IDP_CLIENT_TOKEN_REDIRECT_MISMATCH';
    case TokenPkceFailed = 'IDP_CLIENT_TOKEN_PKCE_FAILED';
    case TokenExchangeFailed = 'IDP_CLIENT_TOKEN_EXCHANGE_FAILED';
    case TokenRefreshFailed = 'IDP_CLIENT_TOKEN_REFRESH_FAILED';
    case ResourceUnauthorized = 'IDP_CLIENT_RESOURCE_UNAUTHORIZED';
    case ResourcePolicyError = 'IDP_CLIENT_RESOURCE_POLICY_ERROR';
    case IamInvalidOrn = 'IDP_CLIENT_IAM_INVALID_ORN';
    case ResourceUnexpectedStatus = 'IDP_CLIENT_RESOURCE_UNEXPECTED_STATUS';
    case MalformedJson = 'IDP_CLIENT_MALFORMED_JSON';
    case WafOrHtmlResponse = 'IDP_CLIENT_WAF_OR_HTML_RESPONSE';
    case HttpTransport = 'IDP_CLIENT_HTTP_TRANSPORT';

    public function readmeAnchor(): string
    {
        return '#error-' . strtolower($this->value);
    }
}
