# Design — Member 09: Contract Renewal Backend (code)

## Scope

Backend service + scoped API for contract list/detail and the renewal-request flow, plus the
nightly expiry scan. Reads the `SupplierContract` schema and the
`Leverancier-contractverlenging-verzoek` case type from member 01.

## Declarative-first (ADR-031) note

No new schema or case type — member 01 owns them. `SupplierContract` via OpenRegister
ObjectService (ADR-001). `renewalWarning` is computed/refreshed by the scan job.

## Approach

- `scanExpiringContracts()` finds contracts with `endDate` within 90 days, sets
  `renewalWarning` = true, computes `daysUntilExpiry`.
- `requestRenewal(contractRef)` creates a Procest zaak of type
  `Leverancier-contractverlenging-verzoek` and emails the account manager.
- `ScanExpiringContractsJob` runs nightly at 03:00 UTC, emailing affected suppliers.
- `ContractController` exposes list/detail/request-renewal, scope-validated via member 04.

## Security (ADR-005)

- Renewal request restricted to contracts or admin roles, scope-validated.
- Procest zaak creation via the OpenRegister REST abstraction (ADR-022).
- Request events written to the contract timeline + audit trail.
