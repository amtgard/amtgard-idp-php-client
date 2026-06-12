# amtgard-idp-php-client

Opinionated PHP client for the [Amtgard Identity Provider](https://github.com/amtgard/amtgard-bastion-idp) OAuth 2.0 authorization code + PKCE flow and resource API.

This library encodes **one** integration path so third-party apps stop re-implementing OAuth details incorrectly:

- Authorization code grant with **PKCE (S256)** — always, including confidential clients
- Scopes: `profile email` (space-separated)
- Resource calls: `GET /resources/userinfo`, `GET /resources/validate`, `GET /resources/jwt`
- Policy evaluation: local `checkAuthorization()` via `amtgard/ork-iam` (backend services)
- Typed results: `TokenSet`, `UserProfile`, `OrkProfile`, `ValidatedSession`, `AuthorizationCheck`

Apps can wire config manually (`IdpClientEnvironment`) or use the on-rails factories that read standard `IDP_*` environment variables.

## Installation

```bash
composer require amtgard/idp-php-client guzzlehttp/guzzle
```

Slim apps should also install Slim to use the bundled auth controller:

```bash
composer require slim/slim
```

## Configuration (`.env`)

Load `.env` before your DI container boots (e.g. `vlucas/phpdotenv` in `public/index.php`). The on-rails factories expect these variables:

```dotenv
IDP_BASE_URL=https://idp.amtgard.com
IDP_CLIENT_ID=my-app
IDP_CLIENT_SECRET=your-confidential-client-secret
IDP_REDIRECT_URI=https://my.app/oauth/callback
# IDP_HTTP_USER_AGENT is optional — defaults to AmtgardIDP/1.0
# IDP_HTTP_USER_AGENT=MyApp/1.0
```

| Variable | Required | Example | Notes |
|----------|----------|---------|-------|
| `IDP_BASE_URL` | Yes | `https://idp.amtgard.com` | No trailing slash |
| `IDP_CLIENT_ID` | Yes | `my-app` | Registered with IDP maintainers |
| `IDP_REDIRECT_URI` | Yes | `https://my.app/oauth/callback` | Must match registration **exactly** |
| `IDP_CLIENT_SECRET` | No | `(secret)` | Omit for public clients (PKCE only) |
| `IDP_HTTP_USER_AGENT` | No | `AmtgardIDP/1.0` | Sent on **every** server-side IDP request (`/oauth/token`, `/resources/*`, `/api/*`). Override only when IDP ops instruct you to. |

## Quick start (on-rails factories)

With `.env` populated, one factory call wires environment, OAuth flow state, and HTTP client:

```php
use Amtgard\IdpClient\Config\IdpClientFactory;

session_start();

$idp = IdpClientFactory::fromEnvVars();

// GET /login
return $idp->beginAuthorization(returnTo: '/dashboard');

// GET /oauth/callback
$session = $idp->completeLogin($request);
// $session->tokens, $session->profile, $session->returnTo
```

Factory chain:

| Factory | Builds |
|---------|--------|
| `IdpClientEnvironmentFactory::fromEnvVars()` | `EnvIdpClientEnvironment` from `IDP_*` vars (throws `IdpConfigurationException` if required vars missing) |
| `IdpClientFactory::fromEnvVars()` | Full `IdpClient` with `SessionOAuthFlowStateStore` + Guzzle |
| `IdpClient::completeLogin()` | Token exchange + `/resources/userinfo` in one call |
| `SessionAuthStore` | Persist `AuthenticatedSession` in `$_SESSION` (framework-agnostic) |

### Session persistence (any PHP app)

```php
use Amtgard\IdpClient\Session\SessionAuthStore;

$authStore = new SessionAuthStore();

// After callback:
$authStore->store($idp->completeLogin($request));

// Later requests:
if ($authStore->isAuthenticated()) {
    $session = $authStore->get();
    $email = $session->profile->email;
}

// Logout:
$authStore->clear();
```

## Manual configuration (custom environments)

When you cannot use `IDP_*` env vars (multi-tenant config, tests, non-`.env` apps), implement `IdpClientEnvironment` yourself or use `ArrayEnvironment`:

```php
use Amtgard\IdpClient\Config\ArrayEnvironment;
use Amtgard\IdpClient\Config\IdpClientFactory;
use Amtgard\IdpClient\OAuth\SessionOAuthFlowStateStore;

$env = new ArrayEnvironment(
    idpBaseUrl: 'https://idp.amtgard.com',
    clientId: 'my-app',
    clientSecret: 'your-secret',
    redirectUri: 'https://my.app/oauth/callback',
);

$idp = IdpClientFactory::fromEnvironment($env, new SessionOAuthFlowStateStore());
```

Equivalent to the on-rails env factory, but explicit:

```php
use Amtgard\IdpClient\Config\IdpClientEnvironmentFactory;

$env = IdpClientEnvironmentFactory::fromEnvVars([
    'IDP_BASE_URL' => 'https://idp.amtgard.com',
    'IDP_CLIENT_ID' => 'my-app',
    'IDP_REDIRECT_URI' => 'https://my.app/oauth/callback',
    'IDP_CLIENT_SECRET' => 'secret',
]);
```

For app-specific env layout, wrap or replace `EnvIdpClientEnvironment` with your own class implementing `IdpClientEnvironment` and pass it to `IdpClientFactory::fromEnvironment()`.

## Public API reference

`IdpClient` is the main entry point. Factories and session helpers wire it; Slim accelerators wrap the OAuth methods.

### `IdpClient`

| Method | Parameters | Returns | Purpose |
|--------|------------|---------|---------|
| `beginAuthorization` | `?string $returnTo = null` | `ResponseInterface` (302) | Start OAuth: generate PKCE verifier/challenge and `state`, store flow state, redirect browser to IDP authorize URL. Optional `$returnTo` is stored and restored after callback. |
| `completeAuthorization` | `ServerRequestInterface $callbackRequest` | `AuthorizationResult` | Finish OAuth on `/oauth/callback`: validate `state`, exchange authorization `code` for tokens. Does **not** fetch user profile. |
| `completeLogin` | `ServerRequestInterface $callbackRequest` | `AuthenticatedSession` | Convenience wrapper: `completeAuthorization()` + `fetchUserProfile()`. Use on callback to get tokens and profile in one call. |
| `fetchUserProfile` | `string $accessToken` | `UserProfile` | `GET /resources/userinfo` — full profile including optional ORK link data and embedded JWT. |
| `validate` | `string $accessToken` | `ValidatedSession` | `GET /resources/validate` — lightweight session heartbeat (`id`, `email`, `jwt`). |
| `fetchJwt` | `string $accessToken` | `string` | `GET /resources/jwt` — fresh authorization JWT string (server may cache for validate/pubsub). |
| `checkAuthorization` | `Policy $policy`, `Requirement $requirement` | `AuthorizationCheck` | Evaluate whether IAM policy claims satisfy a requirement. Uses **local** `amtgard/ork-iam` (`Policy::isAuthorized`) — same logic as the IDP `/api/is_authorized` endpoint, no HTTP round-trip. |
| `policyFromOrns` | `list<string> $orns` | `Amtgard\IAM\Allowance\Policy` | Parse JWT-style policy claim strings into a `Policy` object. |
| `requirementFromOrn` | `string $orn` | `Amtgard\IAM\Requirement\Requirement` | Parse a requirement ORN string into a `Requirement` object. |
| `refresh` | `TokenSet $tokens` | `TokenSet` | Exchange `refresh_token` for a new token set via `POST /oauth/token`. |

### Factories and configuration

| Class | Method | Purpose |
|-------|--------|---------|
| `IdpClientFactory` | `fromEnvVars(?array $env, ?OAuthFlowStateStore, ?ClientInterface)` | On-rails bootstrap from `IDP_*` environment variables. |
| `IdpClientFactory` | `fromEnvironment(IdpClientEnvironment, OAuthFlowStateStore, ?ClientInterface)` | Build `IdpClient` with explicit environment and flow-state store. |
| `IdpClientEnvironmentFactory` | `fromEnvVars(?array $env)` | Parse `IDP_*` vars into `EnvIdpClientEnvironment`. |
| `ArrayEnvironment` | constructor | In-memory `IdpClientEnvironment` for tests or custom config. |

### Session helpers

| Class | Method | Purpose |
|-------|--------|---------|
| `SessionAuthStore` | `store(AuthenticatedSession)` | Persist logged-in session in `$_SESSION`. |
| `SessionAuthStore` | `get(): ?AuthenticatedSession` | Read stored session. |
| `SessionAuthStore` | `clear()` | Log out (remove session data). |
| `SessionAuthStore` | `isAuthenticated(): bool` | Whether a session is stored. |
| `SessionOAuthFlowStateStore` | `put` / `pull` | Store OAuth `state` + PKCE verifier between `/login` and `/oauth/callback` (session-backed). |
| `InMemoryOAuthFlowStateStore` | `put` / `pull` | Same as above, in-memory (unit tests). |

### Slim accelerators (`Amtgard\IdpClient\Slim\`)

| Class | Method | Purpose |
|-------|--------|---------|
| `IdpAuthController` | `login` | Calls `beginAuthorization()`; honors `?return_to=`. |
| `IdpAuthController` | `callback` | Calls `completeLogin()` and `SessionAuthStore::store()`. |
| `IdpAuthController` | `logout` | Clears `SessionAuthStore`. |
| `SessionMiddleware` | `__invoke` | Starts PHP session for OAuth flow state and auth store. |

### Result types

| Type | Fields / notes |
|------|----------------|
| `TokenSet` | `accessToken()`, `refreshToken()`, `expiresIn()`, raw token array |
| `AuthorizationResult` | `tokens`, `?returnTo` |
| `AuthenticatedSession` | `tokens`, `profile` (`UserProfile`), `?returnTo` |
| `UserProfile` | `id`, `email`, `jwt`, `?orkProfile` |
| `OrkProfile` | ORK link fields when user has linked an ORK account |
| `ValidatedSession` | `id`, `email`, `jwt` |
| `AuthorizationCheck` | `isAuthorized` (bool) — `Amtgard\IdpClient\Iam\AuthorizationCheck` |

### IAM types (`amtgard/ork-iam`)

| Type | Namespace | Role |
|------|-----------|------|
| `Policy` | `Amtgard\IAM\Allowance\Policy` | User's IAM claim set — passed to `checkAuthorization()` |
| `Requirement` | `Amtgard\IAM\Requirement\Requirement` | Action/resource being checked — passed to `checkAuthorization()` |

Supporting packages:

- `amtgard/ork-iam-orn-definitions` — registers ORK and Attendance ORN classes
- `Amtgard\IdpClient\Iam\OrnBootstrap` — registers IDP-namespace ORN classes (`Idp` prefix)
- `Amtgard\IdpClient\Iam\OrnParser` — internal parser used by `policyFromOrns()` / `requirementFromOrn()`

Custom integrator `iam_service` namespaces (Client IAM API, future) require additional ORN registration at runtime.

## Resource API

After login, use the access token from `TokenSet` or `AuthenticatedSession`:

```php
$token = $session->tokens->accessToken();

// Full profile (includes optional ORK link data)
$profile = $idp->fetchUserProfile($token);

// Session heartbeat — lighter than userinfo; returns id, email, jwt
$validated = $idp->validate($token);

// Fresh authorization JWT (cached server-side for validate/pubsub)
$jwt = $idp->fetchJwt($token);
```

Backend services can evaluate IAM policies without a user bearer token or extra HTTP call:

```php
use Amtgard\IAM\Allowance\Policy;
use Amtgard\IAM\Requirement\Requirement;

// Build typed ORN objects (from JWT policy claim JSON, config, etc.)
$policy = $idp->policyFromOrns($userPolicyOrnArray);
$requirement = $idp->requirementFromOrn('Idp:0:0:0:0:IDP/EditClient');

$check = $idp->checkAuthorization($policy, $requirement);

if ($check->isAuthorized) {
    // allow action
}
```

`checkAuthorization()` accepts `ork-iam` `Policy` and `Requirement` objects — not raw strings. Use `policyFromOrns()` and `requirementFromOrn()` to parse ORN strings at your API boundary (HTTP handlers, JWT decode, etc.). Evaluation is local via `Policy::isAuthorized()`. Most OAuth client apps only need `fetchUserProfile()` and `validate()`; use policy evaluation when your service already holds a user's IAM policy claim array.

## Slim 4 integration

[Slim 4](https://www.slimframework.com/) apps can use the bundled Slim helpers in `Amtgard\IdpClient\Slim\` — a drop-in auth controller and session middleware. Layout matches other Amtgard PHP projects: PHP-DI `container.php` + `routes.php`.

**Assumptions:** Slim 4, PHP-DI, `vlucas/phpdotenv`, `guzzlehttp/guzzle`, `.env` configured as above.

### On-rails Slim setup (recommended)

**`config/container.php`** — minimal wiring with env factories:

```php
<?php
declare(strict_types=1);

use Amtgard\IdpClient\Client\IdpClient;
use Amtgard\IdpClient\Config\IdpClientFactory;
use Amtgard\IdpClient\Session\SessionAuthStore;
use Amtgard\IdpClient\Slim\IdpAuthController;

return [
    // ... existing definitions

    IdpClient::class => fn () => IdpClientFactory::fromEnvVars(),

    SessionAuthStore::class => fn () => new SessionAuthStore(),

    IdpAuthController::class => function (Psr\Container\ContainerInterface $container) {
        $app = $container->get(Slim\App::class);

        return new IdpAuthController(
            $container->get(IdpClient::class),
            $container->get(SessionAuthStore::class),
            postLoginRoute: 'home',
            postLogoutRoute: 'home',
            routeParser: $app->getRouteCollector()->getRouteParser(),
        );
    },
];
```

**`config/routes.php`** — three routes, library session middleware:

```php
<?php
declare(strict_types=1);

use Amtgard\IdpClient\Slim\IdpAuthController;
use Amtgard\IdpClient\Slim\SessionMiddleware;
use Slim\App;

return function (App $app) {
    $app->get('/', fn ($req, $res) => $res)->setName('home');

    $app->group('', function (Slim\Routing\RouteCollectorProxy $group) {
        $group->get('/login', [IdpAuthController::class, 'login'])->setName('auth.login');
        $group->get('/oauth/callback', [IdpAuthController::class, 'callback'])->setName('auth.callback');
        $group->get('/logout', [IdpAuthController::class, 'logout'])->setName('auth.logout');
    })->add(SessionMiddleware::class);
};
```

The library controller handles:

- **`login`** — `beginAuthorization()` (optional `?return_to=/path` stored for post-login redirect)
- **`callback`** — `completeLogin()` + `SessionAuthStore::store()` + redirect to `return_to` or `home` route
- **`logout`** — `SessionAuthStore::clear()` + redirect to `home` route

Gate protected routes with `SessionAuthStore::isAuthenticated()` in your own middleware.

If you run multiple app instances behind a load balancer, use shared session storage (Redis, etc.) so `/login` and `/oauth/callback` share OAuth flow state — otherwise see [IDP_CLIENT_FLOW_STATE_MISSING](#error-idp_client_flow_state_missing).

### Manual Slim controller (reference)

If you need custom error pages, logging, or redirect logic, use the framework-agnostic helpers directly:

```php
<?php
declare(strict_types=1);

namespace App\Controllers;

use Amtgard\IdpClient\Exception\IdpClientException;
use Amtgard\IdpClient\Client\IdpClient;
use Amtgard\IdpClient\Session\SessionAuthStore;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Routing\RouteContext;

final class IdpAuthController
{
    public function __construct(
        private readonly IdpClient $idpClient,
        private readonly SessionAuthStore $authStore,
    ) {}

    public function login(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $returnTo = $request->getQueryParams()['return_to'] ?? null;

        return $this->idpClient->beginAuthorization(
            is_string($returnTo) ? $returnTo : null,
        );
    }

    public function callback(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        try {
            $session = $this->idpClient->completeLogin($request);
        } catch (IdpClientException $exception) {
            // custom error handling / logging
            return $response->withStatus(400);
        }

        $this->authStore->store($session);

        $redirect = $session->returnTo
            ?? RouteContext::fromRequest($request)->getRouteParser()->urlFor('home');

        return $response->withHeader('Location', $redirect)->withStatus(302);
    }

    public function logout(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $this->authStore->clear();

        return $response
            ->withHeader('Location', RouteContext::fromRequest($request)->getRouteParser()->urlFor('home'))
            ->withStatus(302);
    }
}
```

Register this controller in `container.php` instead of `Amtgard\IdpClient\Slim\IdpAuthController`, and add your own `SessionMiddleware` or use the library's `Amtgard\IdpClient\Slim\SessionMiddleware`.

### Slim checklist

| Step | Route / class | Purpose |
|------|----------------|---------|
| `.env` | `IDP_*` vars | On-rails `IdpClientFactory::fromEnvVars()` |
| Session middleware | `Amtgard\IdpClient\Slim\SessionMiddleware` | PHP session for OAuth flow + auth store |
| `GET /login` | `IdpAuthController::login` | Redirect to IDP authorize |
| `GET /oauth/callback` | `IdpAuthController::callback` | `completeLogin()` + session store |
| `GET /logout` | `IdpAuthController::logout` | Clear `SessionAuthStore` |
| Protected routes | your middleware | `SessionAuthStore::isAuthenticated()` |

## Endpoints (derived from base URL)

| Purpose | URL |
|---------|-----|
| Authorize | `{idpBaseUrl}/oauth/authorize` |
| Token | `{idpBaseUrl}/oauth/token` |
| Userinfo | `{idpBaseUrl}/resources/userinfo` |
| Validate | `{idpBaseUrl}/resources/validate` |
| JWT | `{idpBaseUrl}/resources/jwt` |
| Policy check | Local via `checkAuthorization()` (IDP also exposes `{idpBaseUrl}/api/is_authorized` for non-PHP clients) |

## Error handling

All library exceptions extend `IdpClientException` and expose:

- `errorCode()` — stable string for logging and docs lookup
- `idpError()` / `idpErrorDescription()` — raw IDP OAuth error when present
- `developerHint()` — points to the matching section below

Catch specific types where useful:

| Exception | When |
|-----------|------|
| `InvalidOAuthStateException` | Callback/state/CSRF problems before token exchange |
| `TokenExchangeException` | `/oauth/token` rejected the request |
| `ResourceException` | Resource/API HTTP or JSON problems (`/resources/*`) and IAM ORN parse errors (`checkAuthorization`) |

### Error code reference

Each code maps to a common client implementation mistake. Fix the root cause, then retry the flow from `beginAuthorization()`.

---

#### `IDP_CLIENT_FLOW_STATE_MISSING` {#error-idp_client_flow_state_missing}

**When:** `completeAuthorization()` ran but no flow state was found in your `OAuthFlowStateStore`.

**Common causes:**
- User took too long and the session expired
- `beginAuthorization()` was never called on this browser session
- Session cookie not sent on callback (SameSite, wrong domain, HTTP vs HTTPS mismatch)
- Multiple app instances without shared session storage

**Fix:**
1. Ensure `session_start()` (or your framework session) runs before both `/login` and `/oauth/callback`
2. Use the same session store for both routes
3. Redirect users to `/login` again — do not retry `completeAuthorization()` without a fresh `beginAuthorization()`

---

#### `IDP_CLIENT_STATE_PARAM_MISSING` {#error-idp_client_state_param_missing}

**When:** The callback request has no `state` query parameter.

**Fix:** Verify your redirect URI handler reads query parameters. Do not strip query strings at your reverse proxy.

---

#### `IDP_CLIENT_STATE_MISMATCH` {#error-idp_client_state_mismatch}

**When:** Callback `state` does not match the value stored at `beginAuthorization()`.

**Common causes:**
- Parallel login attempts in the same session
- State stored in a different session than the callback receives
- Custom state generation overriding the library flow

**Fix:** Let this library manage state via `OAuthFlowStateStore`. Do not generate your own `state` for the authorize redirect.

---

#### `IDP_CLIENT_AUTH_CODE_MISSING` {#error-idp_client_auth_code_missing}

**When:** Callback has `state` but no `code`.

**Common causes:**
- User denied consent (`error=access_denied` should appear instead — handle that first)
- Proxy stripped query parameters

**Fix:** Log full callback query params. Handle `error` / `error_description` before expecting `code`.

---

#### `IDP_CLIENT_OAUTH_CALLBACK_ERROR` {#error-idp_client_oauth_callback_error}

**When:** IDP redirected with `error` in the query string (e.g. `access_denied`).

**Fix:** Show a friendly message and link back to login. Do not attempt token exchange.

---

#### `IDP_CLIENT_TOKEN_INVALID_GRANT` {#error-idp_client_token_invalid_grant}

**When:** `/oauth/token` returned `invalid_grant`.

**Common causes:**
- Authorization code already used (codes are single-use)
- Code expired (user waited too long)
- `redirect_uri` on token request differs from authorize request

**Fix:**
1. Use the exact same `redirect_uri` string in config for both steps
2. Start a fresh login — never replay an old `code`

---

#### `IDP_CLIENT_TOKEN_INVALID_CLIENT` {#error-idp_client_token_invalid_client}

**When:** `/oauth/token` returned `invalid_client`.

**Common causes:**
- Wrong `client_id` or `client_secret`
- Confidential client secret sent incorrectly (must be in POST body fields `client_id` + `client_secret`)
- Client not registered on the IDP

**Fix:** Verify credentials with IDP administrators. Match `clientId()` and `clientSecret()` to the registered client.

---

#### `IDP_CLIENT_TOKEN_REDIRECT_MISMATCH` {#error-idp_client_token_redirect_mismatch}

**When:** Token exchange failed because `redirect_uri` does not match registration or the authorize step.

**Fix:**
1. Register the exact callback URL with IDP maintainers (scheme, host, path, no trailing slash drift)
2. Set `redirectUri()` to that exact string
3. League/provider must send the same value on authorize and token requests — this library does that automatically when config is correct

---

#### `IDP_CLIENT_TOKEN_PKCE_FAILED` {#error-idp_client_token_pkce_failed}

**When:** PKCE verification failed at `/oauth/token`.

**Common causes:**
- `code_verifier` not sent on token request
- Verifier regenerated between authorize and token steps
- Wrong challenge method (must be `S256`)
- Base64url encoding wrong (`+`, `/`, `=` padding)

**Fix:** Use this library end-to-end. It stores the verifier in `OAuthFlowStateStore` and sends it automatically. Do not hand-roll PKCE alongside this client.

---

#### `IDP_CLIENT_TOKEN_EXCHANGE_FAILED` {#error-idp_client_token_exchange_failed}

**When:** Token exchange failed with an unrecognized OAuth error.

**Fix:** Inspect `idpError()` and `idpErrorDescription()` on the exception. Check IDP logs. Ensure `POST /oauth/token` uses `Content-Type: application/x-www-form-urlencoded`.

---

#### `IDP_CLIENT_TOKEN_REFRESH_FAILED` {#error-idp_client_token_refresh_failed}

**When:** Refresh token grant was rejected.

**Fix:** Re-authenticate the user via `beginAuthorization()`. Store and rotate refresh tokens when the IDP issues new ones.

---

#### `IDP_CLIENT_RESOURCE_UNAUTHORIZED` {#error-idp_client_resource_unauthorized}

**When:** `GET /resources/userinfo` or `/resources/validate` returned HTTP 401.

**Common causes:**
- Access token expired
- Wrong token sent (ID token vs access token — use **access_token** from `/oauth/token`)
- Missing `Authorization: Bearer` header
- Token for a different environment (prod token against dev IDP)

**Fix:**
1. Send `Authorization: Bearer {access_token}` — this library does this automatically
2. Refresh or re-login if expired

---

#### `IDP_CLIENT_IAM_INVALID_ORN` {#error-idp_client_iam_invalid_orn}

**When:** `checkAuthorization()` received a policy claim or requirement string that `amtgard/ork-iam` cannot parse.

**Common causes:**
- Malformed ORN syntax (missing colons, unknown service prefix)
- Requirement prefix not registered (custom `iam_service` namespaces need runtime ORN registration)
- Policy claim uses wrong proviso segment count for its service format

**Fix:** Validate ORN strings against your IAM service format before calling `checkAuthorization()`. Ensure `amtgard/ork-iam-orn-definitions` is installed for built-in prefixes (ORK, Attendance, Idp).

---

#### `IDP_CLIENT_RESOURCE_POLICY_ERROR` {#error-idp_client_resource_policy_error}

**When:** Resource endpoint returned HTTP 422 (malformed IAM policy on the user account).

**Fix:** User must contact IDP administrators — this is an account configuration issue, not a client bug.

---

#### `IDP_CLIENT_RESOURCE_UNEXPECTED_STATUS` {#error-idp_client_resource_unexpected_status}

**When:** Resource endpoint returned an unexpected HTTP status (5xx, etc.).

**Fix:** Retry with backoff. If persistent, check IDP status with maintainers.

---

#### `IDP_CLIENT_MALFORMED_JSON` {#error-idp_client_malformed_json}

**When:** Response body was not valid JSON.

**Fix:** Often indicates a proxy error page. See `IDP_CLIENT_WAF_OR_HTML_RESPONSE`.

---

#### `IDP_CLIENT_WAF_OR_HTML_RESPONSE` {#error-idp_client_waf_or_html_response}

**When:** `POST /oauth/token` or a resource endpoint returned HTML instead of JSON (often Cloudflare WAF / bot protection). Surfaces as `TokenExchangeException` on callback/refresh and `ResourceException` on resource calls.

**Common causes:**
- Server-side token exchange blocked or challenged by Cloudflare
- Missing or generic `User-Agent` on outbound requests
- Request looks like automated traffic (wrong headers, HTTP/1.0, etc.)

**Fix:**
1. Token exchange **must** happen server-side (never in the browser)
2. Ensure outbound calls use the library default `AmtgardIDP/1.0` (do not strip or replace unless IDP ops require a custom value). The factory applies `httpUserAgent()` to **all** server-side IDP HTTP including `/oauth/token`.
3. Ensure your hosting egress IP is not blocked; contact IDP ops if Cloudflare rules block your server
4. Do not call `/oauth/token` from JavaScript — WAF rules often block that pattern
5. All server-side IDP calls send `Accept: application/json` (token exchange and resources)

---

#### `IDP_CLIENT_HTTP_TRANSPORT` {#error-idp_client_http_transport}

**When:** Underlying HTTP client threw a transport error (DNS, TLS, timeout).

**Fix:** Check network connectivity to `idpBaseUrl()`, TLS certificates, and firewall egress.

---

## Docker Slim example

A runnable reference app lives in [`examples/slim-docker/`](examples/slim-docker/). It uses every Slim accelerator (`IdpClientFactory::fromEnvVars()`, `SessionAuthStore`, `SessionMiddleware`, `IdpAuthController`) behind Docker Compose on port **38080**.

```bash
cp examples/slim-docker/.env.example examples/slim-docker/.env
docker compose -f examples/slim-docker/docker-compose.yml up --build -d
open http://localhost:38080/
```

Default `IDP_BASE_URL` is **https://idp.amtgard.com**. Register your OAuth client with IDP maintainers and set credentials in `examples/slim-docker/.env`. See [examples/slim-docker/README.md](examples/slim-docker/README.md) for the full route map (every `IdpClient` method).

## Testing

```bash
composer install
composer test
composer stan
composer test:coverage   # unit test coverage report
```

### Integration tests (Slim Docker example)

Boots the example app and exercises the full Slim stack over HTTP:

```bash
composer integration:slim
```

Or manually:

```bash
composer integration:slim:up
SLIM_INTEGRATION=1 composer test -- --testsuite Integration --filter SlimDocker
composer integration:slim:down
```

| Variable | Default |
|----------|---------|
| `SLIM_EXAMPLE_URL` | `http://localhost:38080` |
| `IDP_BASE_URL` | `https://idp.amtgard.com` (for callback token-exchange test) |

### Integration tests (live IDP)

Hits the production Amtgard IDP at **https://idp.amtgard.com** by default:

```bash
IDP_INTEGRATION=1 composer test -- --testsuite Integration
```

Optional env vars:

| Variable | Default |
|----------|---------|
| `IDP_BASE_URL` | `https://idp.amtgard.com` |
| `IDP_CLIENT_ID` | `test_phpleague_oauth_client` |
| `IDP_CLIENT_SECRET` | `secret` |
| `IDP_REDIRECT_URI` | `https://your-app.com/callback` |
| `IDP_INTEGRATION_ACCESS_TOKEN` | *(unset — skips happy-path `/resources/*` bearer tests)* |
| `IDP_INTEGRATION_POLICY` | *(unset — skips authorized `checkAuthorization` happy path)* |
| `IDP_INTEGRATION_REQUIREMENT` | *(unset — requirement string paired with `IDP_INTEGRATION_POLICY`)* |

## Relationship to IDP server

| Concern | IDP server | This library |
|---------|------------|--------------|
| Issues tokens | Yes | Consumes tokens |
| User-Agent `AmtgardIDP/1.0` on ORK API | IDP server → ORK | IDP server only |
| User-Agent `AmtgardIDP/1.0` on IDP HTTP | — | **Default** for all OAuth client server-side IDP calls |
| OAuth authorize/token | Yes | Wraps League `GenericProvider` |
| `/resources/userinfo` | Yes | Typed `UserProfile` |
| `/resources/validate` | Yes | Typed `ValidatedSession` |
| `/resources/jwt` | Yes | JWT string |
| `/api/is_authorized` | Yes (HTTP API for non-PHP clients) | Local `checkAuthorization()` via `ork-iam` |

## License

Proprietary — see LICENSE.
