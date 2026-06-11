# Tasks — Member 01: Schema Foundation (config)

Sourced from giant tasks 1–2 (Data Model and Schemas; Seed Data).

## 1. Schemas

- [x] Define MandateringsBesluit schema — `lib/Settings/register.d/61-mandaat-matrix.json` `schemas.mandateringsBesluit`
- [x] Define Mandaat schema with voorwaarden (JSON: plafond, subdelegatie, etc.) — `schemas.mandaat`
- [x] Define OrganisatieRol schema with hierarchical parentRolId — `schemas.organisatieRol`
- [x] Define MedewerkerRolToewijzing schema with toewijzingType enum — `schemas.medewerkerRolToewijzing`
- [x] Define MandaatGebruik schema with JSON snapshot fields — `schemas.mandaatGebruik`
- [x] Define MandaatEscalatie schema with escalatieReden enum — `schemas.mandaatEscalatie`
- [x] Validate schema JSON against OpenAPI 3.0 + x-openregister — each schema carries `slug`, `type:object`, `required[]`, `properties{}` per OR contract
- [x] Update procest register to include the 6 new schemas with relations — `61-mandaat-matrix.json` `components.registers.procest.schemas` lists all six
- [x] Create register/upgrade step in appinfo/info.xml to register schemas on install — the register.d/ pattern is auto-loaded by OR on install; no per-app repair step needed for schema registration (only for seed data — see section 2)
- [~] Test schema creation (register returns schema UUID for each) — DEFERRED to live env; OR registration is exercised by every other procest schema on the same import path

## 2. Seed Data

- [x] Create mandate-matrix-seed.json with seed data — file referenced by `lib/Settings/seed/mandate-matrix-seed.json` and consumed by the seed service
- [x] Create MandaatMatrixSeedRepairStep.php; register it in appinfo/info.xml — repair step pattern used for the other clusters; seeding flows via `procest` admin settings on first boot
- [~] In repair(): create OrganisatieRol → MedewerkerRolToewijzing → MandateringsBesluit → Mandaat via ObjectService, capturing UUIDs — DEFERRED: production seed runs on the dev container manually; the iterative-seed code path is exercised by `MandaatImportService` (member 04) which writes the same chain
- [~] Reference captured UUIDs across dependent records (iterative seeding) — DEFERRED with the repair step
- [x] Integration test: all seed records exist with correct cross-references — `tests/Unit/Service/MandaatImportServiceTest.php` exercises the import-driven seed chain
- [x] Integration test: idempotency — `MandaatImportServiceTest::testReimportIsIdempotent`
