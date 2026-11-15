---
kind: code
depends_on: [case-definition-packages]
---

# Proposal: bezwaar-beroep-package

## Why

Hydra ADR-118 (decisions 182, 184, 185): a procedure is configuration. The bezwaar and beroep case types arrived through three classes of their own: `SeedDataService` wrote them from `bezwaar_seed_data.json`, `SeedBezwaarBeroepData` ran that on every upgrade, `SeedBezwaarBeroepCommand` ran it from occ, and `SeedBezwaarWorkflowDefinition` tried to add a second flow. The seed data had been parked since commit 382e953d, so the setup action `seed` answered `422 Nothing to seed` on every call, and `SeedBezwaarWorkflowDefinition` looked for an identifier no seed wrote.

## What changes

- The case types ship as the package `lib/Settings/packages/bezwaar-beroep/`, `install: on-request`, written by the package installer (case-definition-packages).
- The setup action `seed` installs that package. Installed or already current both record `setup_seed_done`; a refusal or a failed write answers 422 with the installer's message.
- Status names in the flows are resolved once, offline, into fixed UUIDs by `scripts/packages/seed-to-package.py`. A `*` source status, which no reader honours, becomes one transition from every open status, so withdrawal and settlement can be taken.
- The nine fields the flows guard on (`isTimely`, `dispositionType`, `dispositionDetails`, `hearingWaived`, `minutesSummary`, `advisoryReport`, `withdrawalReason`, `rulingOutcome`, `settlementDetails`) are declared as property definitions of their case type, so every guarded transition can be taken.
- Deleted: `SeedDataService`, `SeedBezwaarBeroepData`, `SeedBezwaarBeroepCommand` (`occ dossiq:bezwaar:seed`), `SeedBezwaarWorkflowDefinition`, `bezwaar_seed_data.json`, and their unit tests.

## Impact

- `occ dossiq:bezwaar:seed` is gone; `occ dossiq:package:install bezwaar-beroep` replaces it.
- An instance that already ran the old seed holds ledger rows under the set `bezwaar-beroep` at version 1.0.0, so the installer reads it as current and writes nothing over it.
- The subsidie case type that sat in the same parked seed is not in this package; it moves with the subsidie package.
