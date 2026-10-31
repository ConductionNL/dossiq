# dossiq-app-scaffold delta: bezwaar-beroep-package

## REMOVED Requirements

### Requirement: SeedDataService SHALL idempotently seed bezwaar/beroep case types from a bundled JSON template (REQ-014)

**Reason**: `SeedDataService` and `bezwaar_seed_data.json` are deleted. The case types ship as the case definition package `bezwaar-beroep` (hydra ADR-118).

**Migration**: The package installer writes the package whole or not at all and records each row in the shipped-set ledger (case-types REQ-CT-46 to REQ-CT-50).
