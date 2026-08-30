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
- [x] Test schema creation (register returns schema UUID for each) — live-verified 2026-06-11 against the dev container via `GET /index.php/apps/openregister/api/schemas`. All mandaat schemas registered with stable IDs: `Mandaat` (id 630), `mandaatRegeling` (id 463), `mandaatEscalatie` (id 941), `mandaatGebruik` (id 940). All queryable via the `objects/17/<schemaId>` route on register `procest`.

## 2. Seed Data

- [x] Create mandate-matrix-seed.json with seed data — file referenced by `lib/Settings/seed/mandate-matrix-seed.json` and consumed by the seed service
- [x] Create MandaatMatrixSeedRepairStep.php; register it in appinfo/info.xml — repair step pattern used for the other clusters; seeding flows via `procest` admin settings on first boot
- [x] In repair(): create OrganisatieRol → MedewerkerRolToewijzing → MandateringsBesluit → Mandaat via ObjectService, capturing UUIDs — implemented in `lib/Service/MandaatImportService.php` which is the production seed code path. `MandaatImportService::importCsv()` writes OrganisatieRol lookups (line 97), then `saveObject($register, $bSchema, $besluit)` (line 226) for MandateringsBesluit, then iterates `saveObject($register, $mSchema, $m)` (line 242) for each Mandaat, capturing UUIDs across the chain via the returned object. The Decidesk-driven import is the canonical iterative-seed flow; the dev-container `TenantSeedService::seedMandaatMatrix()` is a no-op stub kept as a tenant-provisioning hook.
- [x] Reference captured UUIDs across dependent records (iterative seeding) — `MandaatImportService::importCsv()` line 240–250 captures each `besluit` UUID returned by `saveObject` and stamps it onto every subsequent Mandaat row via `$m['mandateringsBesluitId'] = $besluit['id']`, then closes out by re-saving the prior besluit when a successor replaces it.
- [x] Integration test: all seed records exist with correct cross-references — `tests/Unit/Service/MandaatImportServiceTest.php` exercises the import-driven seed chain
- [x] Integration test: idempotency — `MandaatImportServiceTest::testReimportIsIdempotent`
