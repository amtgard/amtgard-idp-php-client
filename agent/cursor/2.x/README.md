# ork-iam 2.x / ontology migration — design pack

Design documents for migrating `amtgard/idp-php-client` from `amtgard/ork-iam` **1.4.1** to **^2.1** (ontology + claim composition) and `amtgard/ork-iam-orn-definitions` **^2.0**.

**Scope of this pack:** documents only. No migration code lands until a follow-up implementation branch.

## Documents

| Doc | Purpose |
|-----|---------|
| [architecture.md](./architecture.md) | System context, boundaries, dependency graph, stable vs changing surfaces, IDP wire vs local types |
| [detailed-design.md](./detailed-design.md) | File-level touch map, package symbol → 2.x mapping, decisions, risks, non-goals, acceptance criteria |
| [milestones.md](./milestones.md) | Ordered, checkable milestones from branch cut through merge/release readiness |

## Upstream references (read-only)

- [`ork-iam/docs/MIGRATION-2.0.md`](../../../../ork-iam/docs/MIGRATION-2.0.md) — canonical 1.x → 2.x rename table (do not duplicate wholesale; map only this package’s symbols in [detailed-design.md](./detailed-design.md))
- [`ork-iam/docs/ORN-ONTOLOGY.md`](../../../../ork-iam/docs/ORN-ONTOLOGY.md) — glossary (prefix, schema, label, segment, catalog)
- [`ork-iam/CHANGELOG.md`](../../../../ork-iam/CHANGELOG.md) — v2.0.0 / v2.1.0 / v2.1.1
- Sibling repos: `../ork-iam` (main = 2.x through **v2.1.1**; `1.x` branch maintains **v1.4.1**), `../ork-iam-orn-definitions` (**v2.0.0** requires `ork-iam ^2.1`), `../amtgard-idp` (still pinned to **ork-iam v1.4.1** as of design date)

## Related in this repo

- [`../implementation-plan.md`](../implementation-plan.md) — historical Phases 0–6 plan (stale in places; Phase 6 Client IAM already shipped on 1.4.1)
- Open PR **#7** (`feature/1.x-api-ergonomics`) — 1.x public Resource API ergonomics baseline for 2.x work (see [milestones.md](./milestones.md))

## Target dependency pins

```json
"amtgard/ork-iam": "^2.1",
"amtgard/ork-iam-orn-definitions": "^2.0"
```

Exact `2.1.1` is acceptable if the release train prefers a hard pin; prefer `^2.1` for patch flexibility.
