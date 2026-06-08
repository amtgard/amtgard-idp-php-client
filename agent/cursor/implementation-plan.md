# amtgard-idp-php-client — Implementation Plan

**Status:** Implemented (v1 complete, uncommitted)  
**Sibling server:** [amtgard-idp](https://github.com/amtgard/amtgard-bastion-idp) (`../amtgard-idp`)  
**Production IDP:** `https://idp.amtgard.com` — all integration tests and examples default here  
**Docs reference:** IDP Docsify `/docs` Section 2 (OAuth + resources), Section 4 (League example)

---

## Problem statement

Every new PHP app integrating with the Amtgard IDP repeats the same mistakes:

1. **Generic OAuth configuration** — wrong grant types, missing PKCE/state handling
2. **Endpoint drift** — authorize/token/userinfo URLs copy-pasted and stale
3. **User-Agent confusion** — `AmtgardIDP/1.0` is the IDP **server’s** ORK API user-agent, not what OAuth clients send

This library encodes **one** integration path: **OAuth 2.0 authorization code + PKCE (S256) + `profile email` scopes + resource API + policy evaluation**.

---

## Goals (achieved)

| Goal | Status |
|------|--------|
| On-rails OAuth + PKCE | Done |
| Typed DTOs for all resource responses | Done |
| Framework-agnostic core + optional Slim accelerators | Done |
| On-rails `IDP_*` env factories | Done |
| ~98% unit test coverage | Done (100 tests) |
| Runnable Slim Docker example | Done — covers every `IdpClient` method |
| Live integration against production IDP | Done — default `https://idp.amtgard.com` |

## Non-goals (v1, unchanged)

- ORK deep integration (`/auth/connect`, `/resources/link-ork-profile`)
- Browser session management beyond optional helpers
- Token encryption at rest
- Async HTTP

---

## Package layout (as built)

```
amtgard-idp-php-client/
├── composer.json
├── phpunit.xml.dist
├── phpstan.neon.dist
├── README.md
├── src/
│   ├── IdpClient.php
│   ├── IdpClientFactory.php
│   ├── IdpClientEnvironment.php
│   ├── IdpClientEnvironmentFactory.php
│   ├── EnvIdpClientEnvironment.php
│   ├── ArrayEnvironment.php
│   ├── IdpProvider.php
│   ├── Pkce.php
│   ├── OAuthFlowState.php
│   ├── OAuthFlowStateStore.php
│   ├── SessionOAuthFlowStateStore.php
│   ├── InMemoryOAuthFlowStateStore.php
│   ├── TokenSet.php
│   ├── AuthorizationResult.php
│   ├── AuthenticatedSession.php
│   ├── SessionAuthStore.php
│   ├── UserProfile.php
│   ├── OrkProfile.php
│   ├── ValidatedSession.php
│   ├── AuthorizationCheck.php
│   ├── Http/Psr18IdpHttpClient.php
│   ├── Slim/IdpAuthController.php
│   ├── Slim/SessionMiddleware.php
│   └── Exception/ (ErrorCode, ErrorMapper, typed exceptions)
├── tests/ (unit + Integration/)
└── examples/slim-docker/ (Slim 4 + Docker, port 38080)
```

Namespace: `Amtgard\IdpClient\`

---

## Public API (`IdpClient`)

| Method | Endpoint | Returns |
|--------|----------|---------|
| `beginAuthorization(?returnTo)` | Redirect to `/oauth/authorize` | PSR-7 302 |
| `completeAuthorization($request)` | `/oauth/token` code exchange | `AuthorizationResult` |
| `completeLogin($request)` | Exchange + `/resources/userinfo` | `AuthenticatedSession` |
| `fetchUserProfile($accessToken)` | `GET /resources/userinfo` | `UserProfile` |
| `validate($accessToken)` | `GET /resources/validate` | `ValidatedSession` |
| `fetchJwt($accessToken)` | `GET /resources/jwt` | `string` (JWT) |
| `checkAuthorization($policy, $requirement)` | `POST /api/is_authorized` | `AuthorizationCheck` |
| `refresh($tokens)` | `/oauth/token` refresh grant | `TokenSet` |

Factories:

- `IdpClientEnvironmentFactory::fromEnvVars()`
- `IdpClientFactory::fromEnvVars()` / `fromEnvironment()`

Session helpers:

- `SessionOAuthFlowStateStore` — OAuth CSRF/PKCE flash state
- `SessionAuthStore` — persist `AuthenticatedSession`

Slim accelerators:

- `Slim\IdpAuthController` — `/login`, `/oauth/callback`, `/logout`
- `Slim\SessionMiddleware` — PHP session bootstrap

---

## Slim Docker example — full library coverage

`examples/slim-docker/` maps every public `IdpClient` method to a route:

| Library | Route |
|---------|-------|
| `beginAuthorization` | `GET /login` |
| `completeLogin` | `GET /oauth/callback` |
| `fetchUserProfile` | `GET /resources/userinfo` |
| `validate` | `GET /resources/validate` |
| `fetchJwt` | `GET /resources/jwt` |
| `refresh` | `POST /refresh` |
| `checkAuthorization` | `POST /api/check-authorization` |
| `SessionAuthStore` | `GET /me`, `GET /logout` |

Default `IDP_BASE_URL`: **https://idp.amtgard.com**

---

## Error handling

Stable `ErrorCode` enum + README anchor sections. Exception types:

- `InvalidOAuthStateException` — callback/state/CSRF
- `TokenExchangeException` — `/oauth/token`
- `ResourceException` — `/resources/*` and `/api/is_authorized`
- `IdpConfigurationException` — missing `IDP_*` env vars

---

## Testing

| Suite | Enable | Target |
|-------|--------|--------|
| Unit | `composer test` | Mocked PSR-18, 100 tests |
| IDP integration | `IDP_INTEGRATION=1` | `https://idp.amtgard.com` (overridable via `IDP_BASE_URL`) |
| Slim integration | `SLIM_INTEGRATION=1` | `http://localhost:38080` example app |

Composer scripts: `integration:slim`, `integration:slim:up`, `integration:slim:down`

### Integration env vars

| Variable | Default | Purpose |
|----------|---------|---------|
| `IDP_BASE_URL` | `https://idp.amtgard.com` | Live IDP for all integration tests |
| `IDP_INTEGRATION_ACCESS_TOKEN` | unset | Happy-path bearer resource tests |
| `IDP_INTEGRATION_POLICY` | unset | Authorized `checkAuthorization` test |
| `IDP_INTEGRATION_REQUIREMENT` | unset | Paired requirement string |
| `SLIM_EXAMPLE_URL` | `http://localhost:38080` | Slim example base URL |

### Known integration caveat

`/resources/validate` on the IDP requires an authorization JWT **and** an active IDP browser session. Stateless integration tests may skip the validate happy path even when `IDP_INTEGRATION_ACCESS_TOKEN` is set.

---

## Implementation phases (completed)

### Phase 0 — Scaffold

- [x] `composer.json`, phpunit, phpstan, `.gitignore`
- [x] `agent/cursor/implementation-plan.md`

### Phase 1 — Core OAuth

- [x] Environment contract + `ArrayEnvironment` + `EnvIdpClientEnvironment`
- [x] OAuth flow state stores
- [x] `IdpProvider`, PKCE, `IdpClient` authorize/callback
- [x] `TokenSet`, `AuthorizationResult`, `AuthenticatedSession`
- [x] Unit tests

### Phase 2 — Resources

- [x] `UserProfile`, `OrkProfile`
- [x] `fetchUserProfile`, `validate` → `ValidatedSession`
- [x] `fetchJwt`, `checkAuthorization` → `AuthorizationCheck`
- [x] `refresh`
- [x] `Psr18IdpHttpClient` (GET resources + POST policy API)

### Phase 3 — On-rails + Slim + docs

- [x] `IdpClientFactory`, `IdpClientEnvironmentFactory`
- [x] `SessionAuthStore`, `completeLogin`
- [x] Slim `IdpAuthController`, `SessionMiddleware`
- [x] README with error code reference
- [x] `examples/slim-docker` with full method coverage
- [x] Integration test suites (production IDP default)

### Phase 4 — Release (pending)

- [ ] Initial git commit of library
- [ ] Tag `v1.0.0`
- [ ] Packagist publish (`amtgard/idp-php-client`)

---

## Environment variables (consumer convention)

Read by `IdpClientEnvironmentFactory::fromEnvVars()` — not by `IdpClient` directly:

| Variable | Example |
|----------|---------|
| `IDP_BASE_URL` | `https://idp.amtgard.com` |
| `IDP_CLIENT_ID` | `my-app` |
| `IDP_CLIENT_SECRET` | `(secret)` — omit for public client |
| `IDP_REDIRECT_URI` | `https://my.app/oauth/callback` |
| `IDP_HTTP_USER_AGENT` | `MyApp/1.0 (amtgard-idp-php-client/1.0)` |

---

## Relationship to IDP server

| Concern | IDP server | This library |
|---------|------------|--------------|
| Issues tokens | Yes | Consumes tokens |
| ORK API User-Agent `AmtgardIDP/1.0` | Server-side | **Not used** |
| OAuth authorize/token | Yes | League `GenericProvider` wrapper |
| `/resources/userinfo` | Yes | `UserProfile` |
| `/resources/validate` | Yes | `ValidatedSession` |
| `/resources/jwt` | Yes | JWT string |
| `/api/is_authorized` | Yes | `AuthorizationCheck` |

---

## Open questions (remaining)

1. **Packagist name** — `amtgard/idp-php-client` vs `amtgard/idp-client`
2. **Confidential clients + PKCE** — verify prod IDP accepts both before relaxing PKCE policy
3. **Initial commit** — working tree is complete but not yet committed

---

## References

- Production IDP: https://idp.amtgard.com
- IDP Docsify: `/docs`
- IDP Swagger: `/swagger`
- [PHP League OAuth2 Client](https://oauth2-client.thephpleague.com/)
