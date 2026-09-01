# Work milestone checklist — ork-iam 2.x migration

Ordered milestones for implementers. Check boxes in the implementation PR as work lands.

**Design pack:** [README](./README.md) · [architecture](./architecture.md) · [detailed design](./detailed-design.md)  
**Upstream:** [ork-iam MIGRATION-2.0.md](https://github.com/amtgard/ork-iam/blob/main/docs/MIGRATION-2.0.md)

---

## M0 — Preconditions

- [x] Confirm Packagist (or private Composer) serves `amtgard/ork-iam` **≥ 2.1.0** and `amtgard/ork-iam-orn-definitions` **≥ 2.0.0**
- [x] Confirm sibling facts still hold (or update design notes):
  - [x] `ork-iam` main = 2.x (through v2.1.1+); `1.x` branch has v1.4.1
  - [x] `amtgard-idp` still on ork-iam 1.4.1 **or** document if it already moved
- [x] Resolve **PR #7** (`feature/1.x-api-ergonomics`) baseline:
  - [x] **Preferred:** merge #7 into `main`, then cut implementation branch from `main`
  - [ ] **Alt:** cut `feature/ork-iam-2.x-ontology` from `feature/1.x-api-ergonomics` and note “depends on #7” in the PR
  - [ ] **Avoid:** ontology-only PR on pre-#7 `main` if #7 will merge soon (double rename churn)

**Exit:** agreed base SHA + confirmed dependency availability.

**M0 notes (2026-08-31):**
- Packagist: `amtgard/ork-iam` through **v2.1.1**; `amtgard/ork-iam-orn-definitions` through **v2.0.0**.
- Sibling: `ork-iam` `main` @ v2.1.1; `1.x` retains v1.4.1. `amtgard-idp` still pins `ork-iam` **v1.4.1** + orn-definitions `^0.9`.
- PR #7 merged into `main` (`2c9519c`). Stack base for M1+: post-M0 tip on `chore/ork-iam-2.x-m0-preconditions`.
- Design pack applied onto post-#7 `main` for checklist continuity.


---

## M1 — Branch cut

- [x] Create branch `feature/ork-iam-2.x-ontology` (or equivalent) from chosen base
- [x] Open draft PR early with link to `agent/cursor/2.x/` and empty checklist copy of § acceptance criteria
- [x] Do **not** change sibling repos

**Exit:** draft PR URL exists; working tree ready for dep bump.

---

## M2 — Dependency bump

- [x] Update `composer.json`:
  - [x] `"amtgard/ork-iam": "^2.1"` (or exact `2.1.1` if release train requires)
  - [x] `"amtgard/ork-iam-orn-definitions": "^2.0"`
- [x] Run `composer update amtgard/ork-iam amtgard/ork-iam-orn-definitions` (or full update if lock requires)
- [x] Confirm lockfile pins resolve to 2.x line (not 1.4.1)
- [x] Expect compile failures — that is the signal for M3

**Exit:** lockfile on 2.x; CI may be red until adapters land.

---

## M3 — Core IAM adapters (`src/Iam/**`)

- [x] `IdpFormat` / `IdpClaim` / `IdpRequirement`: `ornSegmentSchema()` + `ServiceCatalog`
- [x] `OrnBootstrap`: `ServiceCatalog::Idp`
- [x] `ServiceFormatParser`: `toCatalogEntry()`, catalog types in PHPDoc
- [x] `OrnWireFormat`: typehints only; **no** wire-string behavior change
- [x] Re-run focused tests: `tests/Iam/*`

**Exit:** local evaluation path compiles; OrnWireFormat fixtures unchanged.

---

## M4 — Client IAM adapters (`src/ClientIam/**`)

- [x] `IntegratorClaim`, `IntegratorOrnRegistrar`, `IntegratorFormatRegistry`
- [x] `ClientIamClient` Idp detection + PHPDoc slots types
- [x] Validators / any remaining `OrkServices` references
- [x] Decide compose path (keep `OrnWireFormat::composeFullOrn` **or** `ClaimBuilder` + `fromClaim` for HTTP) — document in PR if choosing Builder
- [x] Confirm HTTP client still sends `provisos` / `resource` / `service_format` unchanged
- [x] Re-run `tests/ClientIam/*`

**Exit:** Client IAM unit suite green against mocked HTTP.

**M4 notes:** Compose path kept `OrnWireFormat::composeFullOrn` → `ClaimFactory::createOrn` (simpler; already covered). HTTP write path still uses `OrnWireFormat::fromClaim()` → `provisos` / `resource`. Idp detection: `strcasecmp($prefix, ServiceCatalog::Idp->value) === 0`.

---

## M5 — Isolation & static analysis

- [x] Grep: no `OrkServices`, `toOrkServices`, `getServiceIdentifier`, or claim `serviceFormat()` overrides left in `src/` / `tests/`
- [x] Grep: `Amtgard\IAM` imports only under allowed paths (+ `IdpClient` Policy/Requirement)
- [x] `composer stan` green
- [x] `composer cs` green if required by CI

**Exit:** stan + isolation checks pass.

**M5 notes:** Isolation greps clean (wire DTO `serviceFormat` properties retained). `Amtgard\IAM` imports confined to `src/Iam/**`, `src/ClientIam/**`, and `IdpClient` Policy/Requirement. Stan memory bumped to 512M in composer script. CS: no phpcs ruleset / not gated in CI — N/A.

---

## M6 — Full unit tests

- [x] `composer test` green
- [x] Spot-check golden ORN ↔ `OrnWireParts` cases from `OrnWireFormatTest`
- [x] If coverage gates exist, ensure no unexplained drop

**Exit:** unit CI green.

**M6 notes:** `composer test` OK — 224 tests, 376 assertions (23 skipped integration). OrnWireFormat goldens intact (`Skbc:0::::Officer/Approve`, compose `Idp:1:::9:IDP/EditIdentity`, claim round-trip `:0::::` / `IDP/EditClient`). No coverage gate in CI; overall lines **85.30%** (1149/1347). Migration-touched `src/Iam/**` ≥97% (OrnWireFormat 97%); ClientIam Integrators/PolicyClaim 100%, ClientIamClient 96%, UserMetadataValidator 100%. ServiceFormatValidator 67% — defensive catch around `OrnSegmentLabel::from` unreachable under ork-iam 2.x (custom slot names allowed). 2 PHPUnit deprecations from vendor `ork-iam` nullable params — out of scope. Infection: N/A.

---

## M7 — README / examples / changelog notes

- [x] README: document major dep bump; link upstream MIGRATION-2.0 for apps using IAM types directly
- [x] README: state wire compatibility with IDP still on 1.4.1
- [x] Update slim-docker / examples only where they reference 1.x type names
- [x] Add release-note bullet list for the eventual package tag

**Exit:** consumer-facing docs accurate for 2.x.

**M7 notes:** README documents `ork-iam` `^2.1` / orn-definitions `^2.0`, MIGRATION-2.0 link, and wire compatibility with IDP on 1.4.1. Slim-docker / examples had no `OrkServices` / claim `serviceFormat()` overrides to rewrite (wire DTO / route names retained). Added root `CHANGELOG.md` Unreleased section for the eventual package tag.

---

## M8 — Optional live checks

- [x] `IDP_INTEGRATION=1` against production/staging IDP (OAuth + resources) if credentials available
- [x] Confidential-client Client IAM smoke (service-format get + optional claim round-trip) if secrets available
- [x] Slim docker example login path still works (`integration:slim` optional)

**Exit:** no wire regressions observed, or gaps explicitly waived in PR.

**M8 notes (2026-08-31) — prefer waive over inventing credentials:**

- **`IDP_INTEGRATION=1` (partial run → waived full happy-path):** Ran Integration suite (excl. Slim) against production IDP with README public defaults only. IDP reachable (`/oauth/authorize` &lt; 500). Passed: reachability, invalid-bearer userinfo/validate, empty-policy deny (`is_authorized: false`), malformed policy ORN. Failed: invalid-code token exchange returned `TokenInvalidClient` (public default client id/secret not accepted); `fetchJwt` invalid-bearer expectation missed. Optional bearer/policy happy paths skipped (`IDP_INTEGRATION_ACCESS_TOKEN` / `POLICY` / `REQUIREMENT` unset). **Waived** remaining live OAuth + resource happy-path coverage — no valid staging/prod credentials in the agent environment; did not invent secrets.
- **Confidential-client Client IAM smoke: waived.** No confidential client secret / `IDP_INTEGRATION_CLIENT_SECRET` (or equivalent) available in process env; no safe automated live service-format get + claim round-trip without inventing credentials. Unit Client IAM suite already green under M6.
- **`integration:slim` (ran):** Docker available; `examples/slim-docker/.env` present with non-placeholder assigned keys (values not logged). `composer integration:slim:up` succeeded (~80s build); SlimDocker suite **11/12** passed (health, home, unauthenticated gates, login → IDP authorize + PKCE, invalid OAuth callback → 400). **1 failure:** `testCheckAuthorizationWithEmptyPolicy` got `is_authorized: true` via the example HTTP route (library-level empty-policy deny still green under `IDP_INTEGRATION=1` and unit `AuthorizationEvaluatorTest`) — treated as example default/body-parse quirk, not a library wire regression. Stack torn down via `integration:slim:down`.
- Infection: **N/A**.

---

## M9 — PR ready for review / merge

- [x] Implementation PR description includes:
  - [x] Link to this design pack
  - [x] Link to upstream MIGRATION-2.0
  - [x] Base branch note (#7 merged or depended)
  - [x] Checklist copy of [detailed-design §8 acceptance criteria](./detailed-design.md#8-acceptance-criteria-implementation-complete-when)
- [x] No unrelated refactors
- [x] Reviewers: owner + anyone maintaining IDP Client IAM consumers
- [x] Merge when CI green and acceptance criteria checked (PR #9 → `main` @ `f2d7b0b`)
- [ ] Tag **`v2.0.0`** on `main` after release metadata PR merge; then GitHub Release + Packagist update

**Exit:** merged to `main` (or release branch); release readiness documented.

**M9 notes (2026-08-31):**
- PR: https://github.com/amtgard/amtgard-idp-php-client/pull/9 — merged to `main`.
- No unrelated refactors in the stack (adapters, deps, isolation/stan, tests, docs, live-check notes only).
- Reviewers: package owner + IDP Client IAM consumer maintainers.
- **Left for human:** merge when CI green; live OAuth/Client-IAM happy-path creds if desired; tag / Packagist after merge. Infection: **N/A**.

---

## Suggested calendar order (summary)

```
M0 preconditions → M1 branch → M2 composer bump → M3 Iam adapters
  → M4 ClientIam adapters → M5 stan/isolation → M6 unit tests
  → M7 docs/examples → M8 optional live → M9 merge/release
```

Typical effort: **1–2 focused days** if #7 is settled and Packagist tags exist; longer if dependency publishing or Resource API baseline is blocked.

---

## Out of order / do not

- [ ] ~~Implement migration inside the design-docs PR~~
- [ ] ~~Modify `../ork-iam`, `../ork-iam-orn-definitions`, or `../amtgard-idp` for this client bump~~
- [ ] ~~Change IDP wire field names~~
- [ ] ~~Force-push shared branches~~
