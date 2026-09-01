# Detailed design — ork-iam 2.x ontology migration

**Companion:** [architecture.md](./architecture.md) · [milestones.md](./milestones.md) · upstream [MIGRATION-2.0.md](https://github.com/amtgard/ork-iam/blob/main/docs/MIGRATION-2.0.md)

This document maps **this package’s** symbols and files. It does not reproduce the full upstream rename table.

---

## 1. Decisions (locked for implementers)

| ID | Decision | Rationale |
|----|----------|-----------|
| D1 | Target deps: `"amtgard/ork-iam": "^2.1"` and `"amtgard/ork-iam-orn-definitions": "^2.0"` | Matches orn-definitions main; unlocks `ClaimBuilder` / `PolicyBuilder` on 2.x types |
| D2 | **Keep** `OrnWireFormat` / `OrnWireParts` in this client; update typehints only | ork-iam 2.1 has no IDP `{provisos, resource}` wire helper; Client IAM HTTP still needs the split |
| D3 | Prefer `ClaimBuilder` for fluent composition where it simplifies `composeClaim`; do **not** add a local `ClaimComposer` | ClaimComposer never shipped; ClaimBuilder is the 1.4/2.1 API |
| D4 | `IdpClient` method signatures stay stable; ontology renames stay in adapters / IAM modules | Product goal: façade stability across the bump |
| D5 | Enforce ork-iam import isolation: `src/Iam/**`, `src/ClientIam/**` (+ existing `IdpClient` Policy/Requirement types only) | Prevent IAM types leaking into OAuth/Resource/Slim |
| D6 | Client may release on 2.x while IDP remains on 1.4.1 | Wire strings unchanged; local evaluation is in-process |
| D7 | Design assumes **1.x API ergonomics** (PR #7) as the public Resource API baseline | If #7 is still open, either merge first or branch 2.x from #7 and note the dependency |
| D8 | Docs-only for this pack; implementation is a follow-up branch | Avoid mixing design review with code churn |
| D9 | Do not rename IDP JSON keys (`provisos`, `service_format`, …) or DTO property names that mirror wire (`ServiceFormat::$serviceFormat`) unless a separate IDP API version ships | Wire compatibility > local ontology naming purity |
| D10 | Optional rename of **internal** helpers (`ServiceFormatParser` method docs, `serviceFormatSlots()` return type) to “schema/slots” language is OK; **public** `ClientIamClient::getServiceFormat()` / `createServiceFormat()` names stay (they match IDP routes) | Avoid breaking integrator call sites for a rename-only cosmetic |

---

## 2. Package symbol → 2.x mapping

Upstream owns the full table; this is what **appears in this repo**.

| Location / symbol (1.4.1) | 2.x equivalent |
|---------------------------|----------------|
| `Amtgard\IAM\OrkServices` | `Amtgard\IAM\Catalog\ServiceCatalog` |
| `OrkServices::Idp` / `::Configuration` / … | `ServiceCatalog::Idp` / `::Configuration` / … |
| `OrnSegmentLabel::toOrkServices()` | `OrnSegmentLabel::toCatalogEntry()` |
| `Claim::serviceFormat()` (override) | `Claim::ornSegmentSchema()` |
| `Requirement::serviceFormat()` | `ornSegmentSchema()` |
| `ORNFormat::serviceFormat()` | `ORNFormat::ornSegmentSchema()` |
| `$claim->getServiceIdentifier()` | `$claim->getPrefix()` |
| `$claim->ornSegmentSchema()` | Already preferred on 1.4.1 aliases — keep |
| `ClaimBuilder` / `PolicyBuilder` | Same FQCNs; constructors take `ServiceCatalog` / `OrnPrefix` |
| `ClaimComposer` (never shipped) | **N/A** — use `ClaimBuilder` |
| Local `OrnWireFormat` / `OrnWireParts` | **Remain** `Amtgard\IdpClient\Iam\*` |

### Public façade (no rename)

| Method | Notes |
|--------|-------|
| `IdpClient::checkAuthorization(Policy, Requirement)` | FQCNs unchanged |
| `IdpClient::policyFromOrns` / `requirementFromOrn` | Unchanged |
| `IdpClient::clientIam(): ClientIamClient` | Unchanged |
| `ClientIamClient::composeClaim` / `addPolicyClaim*` / `deletePolicyClaim` / `listPolicyClaims` / `policyFromStoredClaims` | Unchanged names; internals use 2.x |
| `ClientIamClient::getServiceFormat` / `createServiceFormat` / `replaceServiceFormat` / `serviceFormatSlots` | Keep names (IDP vocabulary) |

---

## 3. File-level touch map

### 3.1 Must change (compile / type break on ^2.1)

| File | Change |
|------|--------|
| `composer.json` | Bump `ork-iam` → `^2.1`, `ork-iam-orn-definitions` → `^2.0`; refresh lock |
| `src/Iam/Orn/IdpFormat.php` | `OrkServices` → `ServiceCatalog`; `serviceFormat()` → `ornSegmentSchema()` |
| `src/Iam/Orn/IdpClaim.php` | Override `ornSegmentSchema()`; call `IdpFormat::ornSegmentSchema()` |
| `src/Iam/Orn/IdpRequirement.php` | Same as claim |
| `src/Iam/OrnBootstrap.php` | `OrkServices::Idp` → `ServiceCatalog::Idp` |
| `src/Iam/ServiceFormatParser.php` | `OrkServices` → `ServiceCatalog`; `toOrkServices()` → `toCatalogEntry()`; update PHPDoc lists |
| `src/Iam/OrnWireFormat.php` | Typehints `OrkServices` → `ServiceCatalog` (logic unchanged) |
| `src/ClientIam/Iam/IntegratorClaim.php` | `serviceFormat()` → `ornSegmentSchema()`; `getServiceIdentifier()` → `getPrefix()` if used |
| `src/ClientIam/Iam/IntegratorOrnRegistrar.php` | `OrkServices` → `ServiceCatalog` |
| `src/ClientIam/Iam/IntegratorFormatRegistry.php` | PHPDoc / stored list element types |
| `src/ClientIam/ClientIamClient.php` | Imports, `OrkServices::Idp` compare, PHPDoc on slots |
| `src/ClientIam/Validation/PolicyClaimValidator.php` | PHPDoc list types if any |
| `tests/Iam/*` | Mirror renames; assert schema method names |
| `tests/ClientIam/*` | Mirror renames; ClaimBuilder usage if asserted |

### 3.2 Likely unchanged (verify after composer update)

| File | Why |
|------|-----|
| `src/Iam/AuthorizationEvaluator.php` | Uses `Policy` / `Requirement` / `OrnBootstrap` only |
| `src/Iam/OrnParser.php` | Factories + bootstrap |
| `src/Iam/AuthorizationCheck.php` | DTO |
| `src/Iam/OrnWireParts.php` | Plain strings |
| `src/ClientIam/Http/Psr18ClientIamHttpClient.php` | Wire JSON only (`service_format`, `provisos`, `resource`) |
| `src/ClientIam/Model/*` | Wire-shaped DTOs (`serviceFormat` property = JSON key mirror) |
| `src/ClientIam/Validation/ServiceFormatValidator.php` | Uses `OrnSegmentLabel` already — confirm no `OrkServices` |
| `src/Client/IdpClient.php` | Only `Policy` / `Requirement` imports |
| OAuth / Resource / Session / Slim / Exception | No IAM imports |

### 3.3 Consumer-facing docs / examples (as needed)

| Path | When to touch |
|------|----------------|
| `README.md` | Replace any `OrkServices` / `serviceFormat()` snippets; note major dep bump |
| `examples/slim-docker/**` | Only if Client IAM / IAM enums appear in sample code |
| `agent/cursor/implementation-plan.md` | Status pointer only (this pack); do not rewrite Phase 6 fiction |

---

## 4. Adapter patterns

### 4.1 `composeClaim` (recommended shape)

Keep the public signature:

```php
public function composeClaim(array $segments, string $resource): Claim
```

Internal options (either is fine; pick one and test round-trip):

1. **Status quo adapted:** `OrnWireFormat::composeFullOrn($prefix, $schema, $segments, $resource)` → `ClaimFactory::createOrn($orn)`
2. **ClaimBuilder path:** register schema → `ClaimBuilder::forPrefix($prefix)` → `segment(...)` per key → `resource(...)` → `build()`  
   Still use `OrnWireFormat::fromClaim()` on the write path for HTTP.

Do not require callers to construct `ClaimBuilder` themselves for the happy path.

### 4.2 Integrator registration

`IntegratorOrnRegistrar::register(string $service, array $format)` stays string-prefix based (custom `iam_service` values). Built-in Idp detection:

```php
strcasecmp($prefix, ServiceCatalog::Idp->value) === 0
```

`OrnClassMap::registerClaim` / `registerRequirement` APIs follow ork-iam 2.x (prefix as string / catalog — verify against installed `OrnClassMap` during implementation).

### 4.3 IdpFormat

```php
// Before
public static function serviceFormat(): array {
    return [OrkServices::Configuration, OrkServices::Game, OrkServices::Kingdom, OrkServices::Park];
}

// After
public static function ornSegmentSchema(): array {
    return [ServiceCatalog::Configuration, ServiceCatalog::Game, ServiceCatalog::Kingdom, ServiceCatalog::Park];
}
```

`IdpClaim` / `IdpRequirement` must override the abstract 2.x method (`ornSegmentSchema`), not the removed 1.x `serviceFormat`.

---

## 5. IDP coupling matrix

| Client capability | Needs IDP on 2.x? | Notes |
|-------------------|-------------------|-------|
| OAuth / userinfo / validate / jwt | No | |
| Client IAM service-format CRUD | No | Slot names are strings |
| Client IAM policy claim CRUD | No | `provisos` + `resource` strings |
| Local authorization | No | Entirely local |
| Future: shared PHP ORN classes between IDP + client in one monorepo process | Yes | Out of scope |

---

## 6. Risks

| Risk | Impact | Mitigation |
|------|--------|------------|
| PR #7 not merged; 2.x branch based on main | Double churn on Resource API names | Prefer merge #7 first, or branch from `feature/1.x-api-ergonomics` |
| Integrators use `OrkServices` / `getProviso()` in app code | Breaks on client bump even if `IdpClient` signatures stable | README migration note + link to upstream MIGRATION-2.0 |
| Accidental wire change while editing `OrnWireFormat` | IDP rejects claims / silent authz drift | Golden tests: known ORN ↔ `OrnWireParts` fixtures; no change to provisos shape |
| `ork-iam-orn-definitions` ^2.0 not yet on Packagist for CI | Composer fail | Confirm Packagist tags before implementation CI; use path repos only for local |
| Mixing ClaimBuilder schema lookup with unregistered custom prefix | Runtime errors on `composeClaim` | Keep `IntegratorOrnRegistrar` before build (current order) |
| PHPStan noise from list union types | CI red | Align PHPDoc with ork-iam (`ServiceCatalog\|OrnSegmentLabel\|string`) |
| Stale implementation-plan still says ClaimComposer / Phase 6 blocked | Confusion | Status header pointer to this pack (done with design PR) |

---

## 7. Non-goals

- Implementing the migration in this design PR
- Migrating `amtgard-idp` or publishing a coordinated server release
- Moving `OrnWireFormat` into ork-iam
- Renaming public Client IAM methods to ontology vocabulary (`createOrnSegmentSchema`, etc.)
- Replacing IDP JSON key `provisos` with `segments`
- Expanding public surface beyond `IdpClient`
- Porting 1.x leftovers from PR #7 as part of the ontology bump (unless branching from that tip)
- Claiming behavioral authz changes — evaluation semantics must match 1.4.1 for the same ORN strings

---

## 8. Acceptance criteria (implementation complete when…)

- [ ] `composer.json` requires `ork-iam ^2.1` and `ork-iam-orn-definitions ^2.0`; lock resolves on CI
- [ ] No remaining references to `OrkServices`, `serviceFormat()` overrides, `toOrkServices()`, or `getServiceIdentifier()` in `src/` / `tests/` (except historical comments if unavoidable)
- [ ] `IdpClient` public method list and signatures unchanged vs chosen baseline (main or #7)
- [ ] `OrnWireFormat` round-trip tests green; fixture provisos strings identical to pre-migration
- [ ] Client IAM unit tests green (`composeClaim` → add/delete body uses same `provisos`/`resource`)
- [ ] `composer test` and `composer stan` green
- [ ] README documents the major bump + points to upstream MIGRATION-2.0 for app-level type renames
- [ ] Grep confirms `Amtgard\IAM` imports confined to allowed paths (+ `IdpClient` Policy/Requirement)
- [ ] Release notes state: **independent of IDP ork-iam version**; wire compatible with IDP still on 1.4.1

---

## 9. Suggested commit / PR framing (implementation phase)

- Branch: `feature/ork-iam-2.x-ontology` (from main after #7, or from #7 tip)
- PR title: e.g. `Migrate to ork-iam ^2.1 ontology`
- Body: link this design pack + upstream MIGRATION-2.0; checklist from §8 and [milestones.md](./milestones.md)
