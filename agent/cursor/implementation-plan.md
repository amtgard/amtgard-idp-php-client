# amtgard-idp-php-client — Implementation Plan

**Status:** Phases 0–5 complete (OAuth, resources, local IAM, module reorg). **Phase 6 (Client IAM)** is planned — blocked on IDP security validation and `amtgard/ork-iam` `ClaimComposer`.  
**Sibling server:** [amtgard-idp](https://github.com/amtgard/amtgard-bastion-idp) (`../amtgard-idp`)  
**Sibling IAM:** [ork-iam](https://github.com/amtgard/ork-iam) (`../ork-iam`)  
**Production IDP:** `https://idp.amtgard.com`  
**Docs reference:** IDP Docsify `/docs` Section 2 (OAuth + resources), Section 4 (League example), **Section 8 (Client IAM)**

---

## Problem statement

Every new PHP app integrating with the Amtgard IDP repeats the same mistakes:

1. **Generic OAuth configuration** — wrong grant types, missing PKCE/state handling
2. **Endpoint drift** — authorize/token/userinfo URLs copy-pasted and stale
3. **User-Agent** — server-side IDP HTTP defaults to `AmtgardIDP/1.0` (overridable via `IDP_HTTP_USER_AGENT`)
4. **IAM string soup** — hand-authored `provisos` strings (`:0::::`) instead of structured ORN objects

This library encodes **one** integration path: **OAuth 2.0 authorization code + PKCE (S256) + `profile email` scopes + resource API + local policy evaluation + (planned) Client IAM write APIs**.

---

## Goals

| Goal | Status |
|------|--------|
| On-rails OAuth + PKCE | Done |
| Typed DTOs for all resource responses | Done |
| Framework-agnostic core + optional Slim accelerators | Done |
| On-rails `IDP_*` env factories | Done |
| Local policy evaluation via ork-iam | Done |
| Module reorg by concern | Done |
| High unit test coverage | Done (133 tests) |
| Runnable Slim Docker example | Done — covers every `IdpClient` method |
| Live integration against production IDP | Done |
| Client IAM (Section 8) write APIs | **Planned — Phase 6** |

## Non-goals

- ORK deep integration (`/auth/connect`, `/resources/link-ork-profile`)
- Browser session management beyond optional helpers
- Token encryption at rest
- Async HTTP
- Setting `iam_service` via API (admin UI only)
- Admin client management
- `login_id` discovery API (unless IDP adds it to userinfo later)

---

## Package layout (as built)

```
amtgard-idp-php-client/
├── composer.json
├── phpunit.xml.dist
├── phpstan.neon.dist
├── README.md
├── agent/cursor/implementation-plan.md
├── src/
│   ├── Client/
│   │   └── IdpClient.php              # public façade
│   ├── Config/
│   │   ├── IdpClientEnvironment.php
│   │   ├── ArrayEnvironment.php
│   │   ├── EnvIdpClientEnvironment.php
│   │   ├── IdpClientEnvironmentFactory.php
│   │   └── IdpClientFactory.php
│   ├── OAuth/
│   │   ├── IdpProvider.php, Pkce.php, TokenSet.php, …
│   │   └── Http/IdpTokenClient.php
│   ├── Resource/
│   │   ├── Http/Psr18IdpHttpClient.php
│   │   ├── UserProfile.php, OrkProfile.php
│   │   ├── AuthenticatedSession.php, ValidatedSession.php
│   ├── Iam/
│   │   ├── AuthorizationEvaluator.php
│   │   ├── AuthorizationCheck.php
│   │   ├── OrnParser.php
│   │   ├── OrnBootstrap.php
│   │   └── Orn/IdpClaim.php, IdpRequirement.php, IdpFormat.php
│   ├── Session/SessionAuthStore.php
│   ├── Exception/                       # ErrorCode, ErrorMapper, typed exceptions
│   ├── Slim/IdpAuthController.php, SessionMiddleware.php
│   └── ClientIam/                       # Phase 6 — not started
├── tests/                               # mirrors src/ + Integration/
└── examples/slim-docker/
```

Namespace root: `Amtgard\IdpClient\`  
Dependencies: PHP `^8.3`, `amtgard/ork-iam` `1.3.0`, `amtgard/ork-iam-orn-definitions` `^0.9.0`

---

## Public API (`Amtgard\IdpClient\Client\IdpClient`)

| Method | Transport | Returns |
|--------|-----------|---------|
| `beginAuthorization(?returnTo)` | Redirect `/oauth/authorize` | PSR-7 302 |
| `completeAuthorization($request)` | `/oauth/token` code exchange | `AuthorizationResult` |
| `completeLogin($request)` | Exchange + `/resources/userinfo` | `AuthenticatedSession` |
| `fetchUserProfile($accessToken)` | `GET /resources/userinfo` | `UserProfile` |
| `validate($accessToken)` | `GET /resources/validate` | `ValidatedSession` |
| `fetchJwt($accessToken)` | `GET /resources/jwt` | JWT string |
| `checkAuthorization(Policy, Requirement)` | **Local** via ork-iam | `AuthorizationCheck` |
| `policyFromOrns(array $orns)` | Local | `Policy` |
| `requirementFromOrn(string $orn)` | Local | `Requirement` |
| `refresh($tokens)` | `/oauth/token` refresh grant | `TokenSet` |
| `clientIam()` | — | **Phase 6** — `ClientIamClient` |

Factories: `IdpClientEnvironmentFactory::fromEnvVars()`, `IdpClientFactory::fromEnvVars()` / `fromEnvironment()`

Session helpers: `SessionOAuthFlowStateStore`, `SessionAuthStore`

Slim accelerators: `Slim\IdpAuthController`, `Slim\SessionMiddleware`

### ork-iam on the public boundary

`checkAuthorization()`, `policyFromOrns()`, and `requirementFromOrn()` expose `Amtgard\IAM\Allowance\Policy` and `Amtgard\IAM\Requirement\Requirement` directly. Phase 6 will also expose `Amtgard\IAM\Allowance\Claim` on the write path (`addPolicyClaim`, `composeClaim`).

---

## Error handling

Stable `ErrorCode` enum + README anchor sections.

| Exception | When |
|-----------|------|
| `InvalidOAuthStateException` | Callback/state/CSRF |
| `TokenExchangeException` | `/oauth/token` |
| `ResourceException` | `/resources/*` bearer endpoints |
| `IdpConfigurationException` | Missing `IDP_*` env vars |
| `ClientIamException` | **Phase 6** — `/resources/client/*` |

---

## Testing

| Suite | Enable | Target |
|-------|--------|--------|
| Unit | `composer test` | Mocked PSR-18 — **133 tests** |
| IDP integration | `IDP_INTEGRATION=1` | `https://idp.amtgard.com` |
| Slim integration | `SLIM_INTEGRATION=1` | `http://localhost:38080` |

Composer scripts: `integration:slim`, `integration:slim:up`, `integration:slim:down`

### Integration env vars

| Variable | Default | Purpose |
|----------|---------|---------|
| `IDP_BASE_URL` | `https://idp.amtgard.com` | Live IDP |
| `IDP_INTEGRATION_ACCESS_TOKEN` | unset | Bearer resource tests |
| `IDP_INTEGRATION_POLICY` | unset | Authorized `checkAuthorization` test |
| `IDP_INTEGRATION_REQUIREMENT` | unset | Paired requirement ORN |
| `SLIM_EXAMPLE_URL` | `http://localhost:38080` | Slim example base URL |

### Known integration caveat

`/resources/validate` requires an authorization JWT **and** an active IDP browser session. Stateless integration tests may skip the validate happy path.

---

## Completed phases

### Phase 0 — Scaffold

- [x] `composer.json`, phpunit, phpstan, `.gitignore`
- [x] `agent/cursor/implementation-plan.md`

### Phase 1 — Core OAuth

- [x] Environment contract, flow state stores, `IdpProvider`, PKCE
- [x] `IdpClient` authorize/callback, `TokenSet`, `AuthorizationResult`, `AuthenticatedSession`
- [x] Unit tests

### Phase 2 — Resources

- [x] `UserProfile`, `OrkProfile`, `ValidatedSession`
- [x] `fetchUserProfile`, `validate`, `fetchJwt`, `refresh`
- [x] `Psr18IdpHttpClient` (bearer GET `/resources/*`)

### Phase 3 — On-rails + Slim + docs

- [x] `IdpClientFactory`, `IdpClientEnvironmentFactory`
- [x] `SessionAuthStore`, `completeLogin`
- [x] Slim `IdpAuthController`, `SessionMiddleware`
- [x] README with error code reference
- [x] `examples/slim-docker` with full method coverage
- [x] Integration test suites

### Phase 4 — Release

- [ ] Initial git commit of library
- [ ] Tag `v1.0.0`
- [ ] Packagist publish (`amtgard/idp-php-client`)

### Phase 5 — ork-iam integration + module reorg

- [x] `checkAuthorization(Policy, Requirement)` — local `Policy::isAuthorized` (no HTTP to `/api/is_authorized`)
- [x] `policyFromOrns()` / `requirementFromOrn()` via `OrnParser`
- [x] `OrnBootstrap` — register `Idp` prefix claim/requirement classes
- [x] `AuthorizationEvaluator`, `AuthorizationCheck`
- [x] Flat `src/` → module folders (`Client/`, `Config/`, `OAuth/`, `Resource/`, `Iam/`, `Session/`, `Exception/`, `Slim/`)
- [x] Tests: `OrnBootstrapTest`, `OrnParserTest`, `AuthorizationEvaluatorTest`; expanded `IdpTokenClientTest`
- [x] README Public API reference; ORN types documented for `checkAuthorization`

---

## Prerequisites for Phase 6

Phase 6 must not start until both prerequisites are satisfied.

### Prerequisite A — IDP security boundaries (in progress)

Validate isolated edit permissions for client integrators on the IDP server before shipping a write-path client library. The library design assumes the server enforces:

| Boundary | Expected IDP behavior |
|----------|----------------------|
| Authentication | HTTP Basic scoped to the calling OAuth `client_id` only |
| Service format | `GET`/`POST`/`PUT /resources/client/service-format` affects only the authenticated client |
| Policy claims | Add/delete/list limited to claims owned by that client for the given `idp_user_id` |
| User metadata | Put/get/delete scoped to `(idp_user_id, login_id, client_id)` |
| Prefix isolation | Claims must use the client's assigned `iam_service` prefix; cross-prefix writes rejected |
| Confidential-only | Public PKCE clients cannot call `/resources/client/*` |

**IDP reference:** `ClientResourcesController.php`, `UserPolicyClaimRepository.php`, `ClientMetadataValidator.php`

### Prerequisite B — ork-iam `ClaimComposer` (external, not started)

Policy claim composition and IDP wire-format splitting belong in **`amtgard/ork-iam`**, not this library. See [Phase 6 dependency: ork-iam ClaimComposer](#phase-6-dependency-ork-iam-claimcomposer) below.

**Minimum ork-iam release for Phase 6:** `ClaimComposer`, `OrnWireFormat`, `OrnWireParts` (target `ork-iam` `1.4.0` on 1.x branch or `2.0.x` on ontology branch).

---

## Phase 6 — Client IAM API (`v0.14.0`)

**IDP reference:** Section 8 — `templates/api.md`, `ClientResourcesController.php`  
**Auth:** HTTP Basic (`client_id:client_secret`) on all `/resources/client/*` routes  
**Requires:** Confidential OAuth client with admin-assigned **`iam_service`** (ORN prefix)

### IDP feature map

| Concept | IDP field / API | Who configures | Library role |
|---------|-----------------|----------------|--------------|
| ORK service name | `iam_service` (e.g. `Skbc`) | IDP admin | Read via `GET /resources/client/service-format` |
| Service format | `iam_service_format` → `service_format[]` | Admin initial; integrator via API | `GET` / `POST` / `PUT …/service-format` |
| Policy claims | `provisos` + `resource` per row | Integrator backend | `POST` / `DELETE` / `GET …/policy-claims/{idp_user_id}` |
| JWT metadata | `client_metadata` claim | Integrator backend | `PUT` / `GET` / `DELETE …/user-metadata` |

Full ORN = `iam_service` + `provisos` + `resource` (e.g. `Skbc` + `:0::::` + `Officer/Approve`). HTTP bodies send only `provisos` and `resource`; prefix comes from the authenticated client's `iam_service`.

Metadata rules (mirror IDP `ClientMetadataValidator`):

- Scoped per `(idp_user_id, login_id, client_id)` — max **300 bytes** stored
- `encoding: json` (default) — metadata must be a JSON **object**
- `encoding: base64` — base64 string decoding to a JSON object ≤ 300 bytes
- Surfaces in authorization JWT as `client_metadata` when `aud` matches `client_id`

### Gaps in this library

| Area | Have today | Phase 6 deliverable |
|------|------------|---------------------|
| HTTP | Bearer `Psr18IdpHttpClient` | Basic-auth `Psr18ClientIamHttpClient` |
| Service format | — | `getServiceFormat`, `createServiceFormat`, `replaceServiceFormat` |
| Policy claims (write) | Local read/evaluate only | `addPolicyClaim`, `deletePolicyClaim`, `listPolicyClaims` |
| User metadata | — | `putUserMetadata`, `getUserMetadata`, `deleteUserMetadata` |
| Integrator ORN | `Idp` prefix only | `IntegratorClaim` + `IntegratorFormatRegistry` |
| Identifiers | `UserProfile::id` (int) | Document `idp_user_id` (UUID `sub`) + `login_id` |
| Errors | `ResourceException`, etc. | `ClientIamException` + `CLIENT_IAM_*` codes |
| Docs / example | Future mention in README | Section 8 quickstart + Slim routes |

### Module: `src/ClientIam/`

```
ClientIam/
├── ClientIamClient.php              # public sub-client (all Section 8 methods)
├── Http/
│   └── Psr18ClientIamHttpClient.php # Basic auth, JSON decode, error mapping
├── Model/
│   ├── ServiceFormat.php            # iam_service, slots[], is_default
│   ├── ServiceFormatRequest.php
│   ├── PolicyClaim.php              # service, provisos, resource (list response only)
│   ├── PolicyClaimList.php
│   ├── UserMetadata.php
│   └── UserMetadataRequest.php      # idpUserId, loginId, metadata, encoding
├── Validation/
│   ├── PolicyClaimValidator.php     # length + ClaimFactory after registration
│   ├── ServiceFormatValidator.php   # OrnSegmentLabel per slot
│   └── UserMetadataValidator.php    # 300 bytes, json/base64 rules
└── Iam/
    ├── IntegratorClaim.php          # mirrors IDP ClientApplicationClaim
    └── IntegratorFormatRegistry.php
```

Extend shared `src/Iam/`:

- `ServiceFormatParser` — port IDP `IamServiceFormatParser`
- `IntegratorOrnRegistrar` — register format + claim class before validate/write (mirror `OrnClaimRegistry::registerForClient`)

**Not in this library:** `ClaimComposer`, `ClaimParts` — consume from `Amtgard\IAM\Orn\` in ork-iam.

### Public API (`IdpClient::clientIam(): ClientIamClient`)

Requires `clientSecret()` non-empty; throw `IdpConfigurationException` if missing.

| Method | IDP endpoint | Notes |
|--------|--------------|-------|
| `getServiceFormat()` | `GET …/service-format` | Cache `iam_service` + slots |
| `createServiceFormat(ServiceFormatRequest)` | `POST …/service-format` | 409 if already set |
| `replaceServiceFormat(ServiceFormatRequest)` | `PUT …/service-format` | Re-registers ORN parser server-side |
| `composeClaim(array $segments, string $resource)` | — | Thin wrapper over `ClaimComposer::compose()` |
| `addPolicyClaim(string $idpUserId, Claim $claim)` | `POST …/policy-claims` | Serializes via `OrnWireFormat::fromClaim()` |
| `deletePolicyClaim(string $idpUserId, Claim $claim)` | `DELETE …/policy-claims` | 204 |
| `listPolicyClaims(string $idpUserId)` | `GET …/policy-claims/{uuid}` | `PolicyClaimList` |
| `putUserMetadata(UserMetadataRequest)` | `PUT …/user-metadata` | 204 |
| `getUserMetadata(string $idpUserId, int $loginId)` | `GET …/user-metadata/{uuid}?login_id=` | |
| `deleteUserMetadata(string $idpUserId, int $loginId)` | `DELETE …/user-metadata/{uuid}?login_id=` | 204 |

Optional (same release or `v0.14.1`):

- `addPolicyClaimFromOrn(string $idpUserId, string $fullOrn)`
- `policyFromStoredClaims(PolicyClaimList): Policy`
- `decodeAuthorizationJwt(string $jwt): AuthorizationJwtPayload`

### Policy claims — structured `Claim` API

Callers must not hand-author `provisos` strings. Use ork-iam `Claim` objects:

```php
use Amtgard\IAM\Allowance\Claim;
use Amtgard\IAM\ClaimFactory;
use Amtgard\IAM\Orn\ClaimComposer;
use Amtgard\IAM\Orn\OrnWireFormat;

$claim = $iam->composeClaim(
    segments: ['Kingdom' => 123, 'Configuration' => 0],
    resource: 'Editor/Write',
);
$iam->addPolicyClaim($idpUserId, $claim);

// Internally:
$parts = OrnWireFormat::fromClaim($claim);
// POST body: provisos=$parts->provisos, resource=$parts->resource

// Or parse a full ORN after IntegratorClaim is registered:
$claim = ClaimFactory::createOrn('Skbc:0:123::Editor/Write');
```

Expose `provisos` only on **list** responses (`PolicyClaim` DTO). Write path accepts `Claim` only.

### Ergonomics principles

1. **Sub-client, not a second factory** — `$idp->clientIam()` shares environment; OAuth and server-to-server IAM stay separate.
2. **Validate before HTTP** — same rules as IDP; fail fast with `ClientIamException`.
3. **ork-iam at the IAM boundary** — `Claim` for writes, `Policy`/`Requirement` for evaluation; string DTOs only for HTTP list responses and metadata JSON.
4. **UUID vs int** — `idp_user_id` is always UUID (`sub`); never `UserProfile::id`.
5. **`login_id` is explicit** — not inferable from OAuth today.
6. **Confidential-only** — document that public PKCE clients cannot call Client IAM.

### Typical integrator flow

```
1. Admin assigns iam_service on OAuth client
2. Backend: $iam = $idp->clientIam()
3. $iam->getServiceFormat()                    // prefix + slots
4. User completes OAuth → $session = $idp->completeLogin(...)
5. $claim = $iam->composeClaim(['Kingdom' => 123], 'Editor/Write')
   $iam->addPolicyClaim($uuidFromSub, $claim) // NOT $session->profile->id
6. $iam->putUserMetadata(new UserMetadataRequest(..., loginId: 42, metadata: ['tier' => 2]))
7. $jwt = $idp->fetchJwt($session->tokens->accessToken())
8. $policy = $idp->policyFromOrns($decodedPolicyOrns)
   $idp->checkAuthorization($policy, $idp->requirementFromOrn('Skbc::123::Editor/Write'))
```

### Implementation sub-phases

| Sub-phase | Deliverable | Depends on | Tests |
|-----------|-------------|------------|-------|
| **6-pre** | IDP security validation complete | — | IDP test suite |
| **6-pre** | ork-iam `ClaimComposer` + `OrnWireFormat` shipped | — | ork-iam unit tests |
| **6a** | `IntegratorClaim`, `IntegratorFormatRegistry`, `ServiceFormatParser`, validators; bump `ork-iam` dep | 6-pre | Unit: ORN round-trip, invalid slots, metadata limits, custom slot names |
| **6b** | `Psr18ClientIamHttpClient`, DTOs, `ClientIamException` + `ErrorCode` | — | Unit: Basic auth, request shaping, 401/404/409/400 mapping |
| **6c** | `ClientIamClient`, `IdpClient::clientIam()`, factory wiring | 6a, 6b | Unit: requires secret, format cache after GET |
| **6d** | Slim example routes + README Section 8 | 6c | Integration: `IDP_INTEGRATION_CLIENT_SECRET` + `iam_service` test client |
| **6e** (optional) | `AuthorizationJwtPayload` decode helper | — | Unit only |

### New / extended environment variables

| Variable | Required | Purpose |
|----------|----------|---------|
| `IDP_CLIENT_SECRET` | Yes for Client IAM | Basic auth |
| `IDP_IAM_SERVICE` | Optional | Offline ORN validation without GET |
| `IDP_IAM_SERVICE_FORMAT` | Optional | JSON array, e.g. `["Configuration","Kingdom"]` |
| `IDP_INTEGRATION_CLIENT_SECRET` | Integration | Confidential test client |
| `IDP_INTEGRATION_IAM_SERVICE` | Integration | Test client `iam_service` |

### Documentation deliverables

| Doc | Content |
|-----|---------|
| README — Client IAM section | Prerequisites, flow, method table, UUID vs int, metadata encoding |
| README — Public API | `clientIam()` + `ClientIamClient` method table |
| README — error codes | `CLIENT_IAM_*` reference |
| README — env vars | Optional offline validation vars |
| `examples/slim-docker` | `ClientIamController` gated on secret + `iam_service` |

### Release

- **Target:** `v0.14.0` — additive; no breaking changes to OAuth/resource APIs
- **Requires:** `amtgard/ork-iam` `^1.4` (or `^2.0` when ontology ships) with `ClaimComposer`
- **Requires:** confidential integrator test client on staging/prod for live integration suite

---

## Phase 6 dependency: ork-iam ClaimComposer

**Owner:** `amtgard/ork-iam` (implement separately; this library consumes it)

### Problem

ork-iam today supports **parse** (`ClaimFactory::createOrn`) and **rebuild** (`Claim::buildOrn`), but not:

1. Compose a `Claim` from prefix + schema + labeled segments + resource (without hand-writing ORN strings)
2. Split a `Claim` into IDP wire parts (`provisos` + `resource`)

### Proposed ork-iam layout

```
src/Orn/
├── OrnWireParts.php       # readonly: prefix, provisos, resource
├── OrnWireFormat.php      # composeFullOrn(), decompose(), fromClaim()
├── OrnComposer.php        # optional fluent builder
├── ClaimComposer.php      # compose() → Claim
└── RequirementComposer.php # compose() → Requirement (optional)
```

Namespace: `Amtgard\IAM\Orn\`

### `OrnWireParts`

```php
readonly final class OrnWireParts
{
    public function __construct(
        public OrnPrefix $prefix,
        public string $provisos,   // always starts with ':', e.g. ':0::::'
        public string $resource,   // e.g. 'Officer/Approve'
    ) {}

    public function fullOrn(): string
    {
        return $this->prefix->name . $this->provisos . $this->resource;
    }
}
```

### `OrnWireFormat`

**Compose** (string only, no factory):

```php
OrnWireFormat::composeFullOrn(
    prefix: 'Skbc',
    schema: ['Configuration', 'Kingdom', 'Park'],  // ordered
    segments: ['Kingdom' => 123, 'Configuration' => 0],
    resource: 'Editor/Write',
);
// → 'Skbc:0:123::Editor/Write'
```

Rules:

- Unmentioned schema slots → empty string
- Unknown segment keys → `InvalidArgumentException` (strict)
- Allowed values: `int`, `'*'`, empty/null
- Resource map validation deferred to `ClaimFactory`

**Decompose**:

```php
OrnWireFormat::decompose('Skbc:0::::Officer/Approve');
// → prefix=Skbc, provisos=':0::::', resource='Officer/Approve'

OrnWireFormat::fromClaim($claim);  // decompose($claim->buildOrn())
```

### `ClaimComposer`

```php
ClaimComposer::compose(
    prefix: $iamService,
    schema: $serviceFormatSlots,   // explicit — required for integrator prefixes
    segments: ['Kingdom' => 123],
    resource: 'Editor/Write',
): Claim;  // ClaimFactory::createOrn($composedString)
```

Schema resolution for **builtin** prefixes (ORK, Attendance) may be optional sugar via `OrnSchemaResolver` reading `*Format::ornSegmentSchema()` from orn-definitions. **Integrator prefixes** always pass schema explicitly (runtime registry is app-level, not ork-iam).

### idp-php-client consumption

```php
// ClientIamClient::composeClaim() — thin wrapper
return ClaimComposer::compose(
    prefix: $this->iamService(),
    schema: $this->serviceFormatSlots(),
    segments: $segments,
    resource: $resource,
);

// ClientIamClient::addPolicyClaim() — serialize
$parts = OrnWireFormat::fromClaim($claim);
$this->http->post('policy-claims', [
    'idp_user_id' => $idpUserId,
    'provisos'      => $parts->provisos,
    'resource'      => $parts->resource,
]);
```

### ork-iam tests to add (in ork-iam repo)

1. Round-trip: compose → `Claim` → `buildOrn()` → decompose → same `OrnWireParts`
2. IDP fixture: `Skbc:0::::Officer/Approve` → `provisos=':0::::'`
3. Custom labels: `ProvisoExample:42:7:Widget/Read`
4. Partial segments, wildcards, strict unknown keys
5. Requirement parity via `RequirementComposer`

---

## Source organization principles

1. **One concern per folder** — OAuth never imports ClientIam; Iam never imports Slim.
2. **`IdpClient` is the only public façade** — sub-clients are methods on it.
3. **ork-iam isolation** — only `src/Iam/` and `ClientIam/Iam/` import `Amtgard\IAM\*`.
4. **HTTP clients colocated with domain** — OAuth/Resource/ClientIam each own their HTTP client.

### Optional follow-up (Phase C)

Slim `IdpClient` to a thinner façade if it grows further. Not blocking Phase 6.

### Open: `Resource/` vs `Model/`

`Resource/` today means IDP `/resources/*` API DTOs. Client IAM DTOs will live in `ClientIam/Model/`. Renaming `Resource/` → `Model/` is optional and can be deferred.

---

## ork-iam version strategy

| Library version | ork-iam | Notes |
|-----------------|---------|-------|
| Current | `1.3.0` | Local evaluation, `OrnSegmentLabel` aliases |
| Phase 6 | `^1.4` | Requires `ClaimComposer` |
| Future | `^2.0` | Ontology rename per `ork-iam/docs/MIGRATION-2.0.md`; swap adapters in `src/Iam/` and `ClientIam/Iam/` only |

When `ork-iam` 2.0 ships: `Proviso` → `OrnSegment`, `getProviso` → `getSegment`, `OrkServices` → `ServiceCatalog`. Public `IdpClient` method signatures stay stable; internal imports change.

---

## Environment variables (consumer convention)

Read by `IdpClientEnvironmentFactory::fromEnvVars()`:

| Variable | Example |
|----------|---------|
| `IDP_BASE_URL` | `https://idp.amtgard.com` |
| `IDP_CLIENT_ID` | `my-app` |
| `IDP_CLIENT_SECRET` | `(secret)` — required for Client IAM |
| `IDP_REDIRECT_URI` | `https://my.app/oauth/callback` |
| `IDP_HTTP_USER_AGENT` | `AmtgardIDP/1.0` (default) |

---

## Relationship to IDP server

| Concern | IDP server | This library |
|---------|------------|--------------|
| Issues tokens | Yes | Consumes tokens |
| OAuth authorize/token | Yes | League `GenericProvider` wrapper |
| `/resources/userinfo` | Yes | `UserProfile` |
| `/resources/validate` | Yes | `ValidatedSession` |
| `/resources/jwt` | Yes | JWT string |
| `/api/is_authorized` | Yes (HTTP for non-PHP) | **Local** `checkAuthorization()` via ork-iam |
| `/resources/client/*` | Yes (Section 8) | **Phase 6** — `ClientIamClient` |
| Integrator security boundaries | Yes | Assumed; validated in Prerequisite A |

---

## Open questions

1. **Packagist name** — `amtgard/idp-php-client` vs `amtgard/idp-client`
2. **Confidential clients + PKCE** — verify prod IDP accepts both before relaxing PKCE policy
3. **ork-iam 2.0 timing** — ship Phase 6 on `^1.4` or wait for `^2.0`?
4. **JWT decode helper** — `firebase/php-jwt` as optional `suggest`, or document manual base64 only?
5. **Resource → Model rename** — defer or do with Phase 6?

---

## References

- Production IDP: https://idp.amtgard.com
- IDP Docsify: `/docs` (Section 8 = Client IAM)
- IDP Swagger: `/swagger`
- ork-iam ontology: `../ork-iam/docs/ORN-ONTOLOGY.md`
- ork-iam 2.0 migration: `../ork-iam/docs/MIGRATION-2.0.md`
- [PHP League OAuth2 Client](https://oauth2-client.thephpleague.com/)
