# Changelog

All notable changes to `amtgard/idp-php-client` are documented here.

## Unreleased (next package tag — ork-iam 2.x ontology)

### Changed

- Require `amtgard/ork-iam` `^2.1` and `amtgard/ork-iam-orn-definitions` `^2.0` (major dependency bump from `ork-iam` `1.4.1` / orn-definitions `^0.9`).
- Adapt internal IAM adapters (`src/Iam/**`, `src/ClientIam/**`) to the 2.x ontology (`ServiceCatalog`, `ornSegmentSchema()`, `toCatalogEntry()`, etc.).
- `IdpClient` public method list and signatures are unchanged.

### Compatibility

- **Wire-compatible with an IDP still on `ork-iam` 1.4.1.** ORN strings and IDP JSON keys (`provisos`, `service_format`, …) are unchanged; only local PHP type/API names follow the 2.x ontology.
- Apps that use `Amtgard\IAM\*` types directly must apply the upstream renames — see [ork-iam MIGRATION-2.0](https://github.com/amtgard/ork-iam/blob/main/docs/MIGRATION-2.0.md).

### Migration notes for integrators

| 1.x (do not use) | 2.x |
|------------------|-----|
| `OrkServices` | `ServiceCatalog` |
| Claim/requirement `serviceFormat()` override | `ornSegmentSchema()` |
| `toOrkServices()` | `toCatalogEntry()` |
| `getServiceIdentifier()` | `getPrefix()` |

Client IAM HTTP method names (`getServiceFormat`, `createServiceFormat`, …) stay — they mirror IDP routes, not ork-iam type names.
