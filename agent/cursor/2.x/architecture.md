# Architecture — ork-iam 2.x ontology migration

**Package:** `amtgard/idp-php-client`  
**From:** `amtgard/ork-iam` `1.4.1` + `ork-iam-orn-definitions` `^0.9`  
**To:** `amtgard/ork-iam` `^2.1` + `ork-iam-orn-definitions` `^2.0`  
**Companion upstream:** [ork-iam MIGRATION-2.0.md](https://github.com/amtgard/ork-iam/blob/main/docs/MIGRATION-2.0.md)

---

## 1. System context

```
┌─────────────────────────────────────────────────────────────────┐
│  Integrator PHP app                                             │
│    IdpClient (sole public façade)                               │
│      ├── OAuth / Resource HTTP  ──────────────► Amtgard IDP     │
│      │                                           (HTTP wire)    │
│      ├── clientIam() ─────────────────────────► /resources/     │
│      │                                           client/*       │
│      └── checkAuthorization / policyFromOrns    (local only)    │
│            └── Amtgard\IAM\* (ork-iam 2.x)                      │
│                  + orn-definitions register.php                 │
└─────────────────────────────────────────────────────────────────┘
```

| Concern | Owner | ork-iam version coupling |
|---------|-------|--------------------------|
| OAuth PKCE, token exchange, resource GET | This client → IDP HTTP | **None** (bearer tokens / JSON) |
| Client IAM write API | This client → IDP HTTP + local claim parse | **Wire strings** independent; **types** use client’s ork-iam |
| Local `Policy::isAuthorized` | Client process only | Client’s ork-iam only |
| IDP server evaluation / storage | `amtgard-idp` process | IDP’s ork-iam (today **1.4.1**) |

**Implication:** this package can bump to ork-iam 2.1 **without** waiting for IDP to migrate, as long as ORN **wire strings** and IDP JSON field names stay compatible (they do — ontology is a PHP type/API rename, not a wire change).

---

## 2. Boundaries

### 2.1 Public façade (stable)

`Amtgard\IdpClient\Client\IdpClient` remains the **only** supported entry point. Sub-capabilities stay method-gated (`clientIam()`, etc.).

| Surface | Stability across 2.x bump |
|---------|---------------------------|
| Method names / parameter shapes on `IdpClient` (OAuth, resources, IAM helpers, `clientIam()`) | **Keep stable** where possible |
| Return types that are package DTOs (`UserProfile`, `AuthenticatedSession`, `AuthorizationCheck`, …) | **Stable** |
| Return / param types that are `Amtgard\IAM\Allowance\Policy`, `Requirement`, `Claim` | **Same FQCNs**; internal ontology renames inside those types are a **consumer-visible** ork-iam break if apps call `getProviso()` / `OrkServices` themselves |
| `ClientIamClient` method names | **Stable**; PHPDoc / internal types move `OrkServices` → `ServiceCatalog` |

### 2.2 ork-iam isolation (hard rule)

Only these areas may `use Amtgard\IAM\*`:

| Allowed | Examples |
|---------|----------|
| `src/Iam/**` | `OrnBootstrap`, `OrnParser`, `OrnWireFormat`, `IdpClaim` / `IdpRequirement` / `IdpFormat`, evaluators |
| `src/ClientIam/Iam/**` | `IntegratorClaim`, `IntegratorOrnRegistrar`, `IntegratorFormatRegistry` |
| Closely related Client IAM helpers | `ClientIamClient`, validators that call `ClaimFactory` / `OrnWireFormat`, `ServiceFormatParser` |

**Exception (already true today):** `IdpClient` imports `Policy` and `Requirement` solely to type the public evaluation API. Do not spread further IAM imports into OAuth, Resource, Slim, or Config.

### 2.3 IDP HTTP wire vs local types

| Layer | Format | Who defines it |
|-------|--------|----------------|
| Full ORN string | `Prefix:seg0:seg1:…:Resource[/Procedure]` | ork-iam `buildOrn()` / `ClaimBuilder::buildOrnString()` / this package’s `OrnWireFormat::composeFullOrn()` |
| IDP Client IAM body | `{ "provisos": ":0::::", "resource": "Officer/Approve" }` (+ Basic auth) | IDP API (Section 8) — **not** renamed by ontology |
| Service format body | `{ "service_format": ["Configuration","Game",…] }` | IDP JSON — string slot names; enum type is local only |
| Stored claims list | Prefix + provisos + resource fields reconstituted to full ORN in DTOs | This client’s models |

**Invariant:** ontology migration must not change wire strings or JSON keys. Adapters map 2.x types ↔ the same strings IDP already stores.

---

## 3. Dependency graph (target)

```
amtgard/idp-php-client
  ├── php ^8.3
  ├── amtgard/ork-iam ^2.1          (Claim, Policy, Requirement, ClaimBuilder, PolicyBuilder, ServiceCatalog, OrnPrefix, …)
  ├── amtgard/ork-iam-orn-definitions ^2.0   (requires ork-iam ^2.1; register.php for ORK/Attendance/…)
  ├── league/oauth2-client …
  └── psr/http-* …

amtgard-idp (sibling, unchanged by this migration)
  └── amtgard/ork-iam v1.4.1        ← may lag; OK for independent client cutover
```

`ork-iam` **main** = 2.x through **v2.1.1**. Maintenance **1.x** branch retains **v1.4.1**.

---

## 4. What stays stable vs what changes

### Stable

- `IdpClient` public method set (OAuth + resources + `checkAuthorization` / `policyFromOrns` / `requirementFromOrn` / `clientIam` / `refresh`)
- IDP endpoints and JSON field names (`provisos`, `resource`, `service_format`, …)
- Error codes / exception types for Client IAM and resource failures
- Slim / Docker example **behavior** (only type/import updates if examples touch IAM enums)
- Decision: **do not** re-implement claim composition in this library — consume ork-iam **`ClaimBuilder`** / **`PolicyBuilder`** (1.4 already used `ClaimBuilder`; planned `ClaimComposer` name never shipped)

### Changes (internal + adapter)

- All `OrkServices` → `ServiceCatalog`
- `serviceFormat()` overrides → `ornSegmentSchema()`
- `toOrkServices()` → `toCatalogEntry()`
- `getServiceIdentifier()` → `getPrefix()` where still referenced
- PHPDoc / typehints `list<OrkServices|string>` → `list<ServiceCatalog|string>` (or `OrnSegmentLabel|ServiceCatalog|string` to match ork-iam)
- `composer.json` pins as above
- Tests and README snippets that mention 1.x type names

### Explicit ownership decision — `OrnWireFormat` / `OrnWireParts`

| Option | Verdict |
|--------|---------|
| Migrate into ork-iam 2.1 | **Reject for this release** — 2.1 has `ClaimBuilder::buildOrnString()` but **no** IDP-oriented `{prefix, provisos, resource}` splitter |
| Delete and call only `ClaimBuilder` | **Insufficient** — Client IAM HTTP still needs `OrnWireParts` for `provisos` + `resource` body fields |
| **Keep in this client** (`src/Iam/OrnWireFormat.php`, `OrnWireParts.php`) | **Chosen** — update typehints to `ServiceCatalog`; optionally use `ClaimBuilder` inside `composeClaim` while still splitting via `OrnWireFormat::fromClaim()` for HTTP |

Thin-wrap later if ork-iam ever adds an official wire helper; not a blocker.

---

## 5. Interaction model (Client IAM)

```
composeClaim(segments, resource)
  → register integrator schema (IntegratorOrnRegistrar)
  → build full ORN string (OrnWireFormat and/or ClaimBuilder)
  → ClaimFactory::createOrn(fullOrn)     // ork-iam 2.x

addPolicyClaim(userId, Claim)
  → OrnWireFormat::fromClaim($claim)    // local wire split
  → HTTP POST { provisos, resource }    // IDP unchanged
```

Local evaluation path is unchanged architecturally:

```
policyFromOrns / requirementFromOrn → OrnParser → PolicyFactory / RequirementFactory
checkAuthorization → AuthorizationEvaluator → Policy::isAuthorized
```

---

## 6. Independent vs coordinated cutover

| Scenario | Client on 2.1, IDP on 1.4.1 |
|----------|----------------------------|
| OAuth + resource GETs | Fine |
| Client IAM writes (string provisos/resource) | Fine — same wire |
| Local `checkAuthorization` in the client app | Fine — local 2.x |
| Sharing in-memory `Claim` objects between IDP and client in one process | **Not supported** today; would require coordinated versions |
| Integrator apps that subclassed 1.x `Claim` / called `serviceFormat()` | Break until they migrate (document in README changelog) |

**Recommendation:** ship client 2.x when ready; track IDP ontology migration as a **separate** program. Call out in release notes that server and client ork-iam majors may differ.

---

## 7. Baseline API surface for the migration branch

Prefer implementing 2.x on top of the **1.x API ergonomics** target:

- Short names `fetchUserProfile` / `validate` / `fetchJwt` = OAuth **access token**
- `*WithAuthorizationJwt` for JWT bearer paths
- `*ForSession` helpers with cookie replay

That surface lives on open PR **#7** (`feature/1.x-api-ergonomics`). **main** today still has the pre-ergonomics naming (`fetchUserProfile` = authorization JWT). See [milestones.md](./milestones.md) for branch-order options.

---

## 8. Non-architectural reminders

- Slim / README / examples: touch only for consumer-facing type renames
- No new public IAM façade classes
- No wire-format “improvements” that alter provisos string shape
