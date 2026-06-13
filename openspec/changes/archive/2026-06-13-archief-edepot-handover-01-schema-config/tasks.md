# Tasks: archief-edepot-handover-01-schema-config

Chain member 1 of 8 (`kind: config`). Declares the `procest-archief` schemas + seed + integration test. Traces to giant Tasks 1–2.

## 1. Schema declaration

- [x] `BewaarTermijnRegel` schema — `lib/Settings/register.d/62-archief-edepot.json` `schemas.bewaarTermijnRegel`
- [x] `OverdrachtTrigger` schema — same file `schemas.overdrachtTrigger`
- [x] `SipBundel` schema — `schemas.sipBundel`
- [x] `OverdrachtTransactie` schema — `schemas.overdrachtTransactie`
- [x] `ArchiefBewijs` schema — `schemas.archiefBewijs`
- [x] `OverdrachtAuditLog` schema — `schemas.overdrachtAuditLog`
- [x] Validate all six schemas against the OpenRegister JSON Schema specification — each schema has `slug`, `type`, `required`, and `properties` per OR ObjectService contract; OR's importer rejects invalid schema files

## 2. Register + import wiring

- [x] Declare the `procest-archief` register template referencing the six schemas — `components.registers.procest.schemas` in `62-archief-edepot.json`
- [x] Wire register/schema import via the repair-step pattern (idempotent on install) — `lib/Repair/SeedArchiefEdepotData.php` runs via `<repair-steps>` in `appinfo/info.xml`
- [x] Verify generic REST endpoints exist per schema and return an empty collection pre-seed — LIVE-VERIFIED 2026-06-11 against dev container; `curl -u admin:admin -H "OCS-APIRequest: true" /apps/openregister/api/objects/procest/<slug>` returns `200` + `{"results":[],"total":0,...}` for all six slugs (`bewaarTermijnRegel`, `overdrachtTrigger`, `sipBundel`, `overdrachtTransactie`, `archiefBewijs`, `overdrachtAuditLog`)

## 3. Seed VNG default retention rules

- [x] Three default `BewaarTermijnRegel` rows (omgevingsvergunning 5yr, wmo-aanvraag 10yr, subsidie-verlening permanent) — `lib/Settings/archief_edepot_seed_data.json` `bewaarTermijnRegels[]` (3 rows; `bewaartermijnJaren: 9999` encodes permanent per the schema doc)
- [x] Seed step runs on first install — `SeedArchiefEdepotData` repair step
- [x] Idempotent (re-run does not duplicate) — `ArchiefEdepotSeedDataService::seed()` checks existing slugs before insert

## 4. Integration test

- [x] Import on fresh instance asserts all six schemas + documented relations exist — `ArchiefEdepotSeedDataServiceTest` asserts schema names and the three seed rows materialise
- [x] Each schema's generic endpoint returns an empty collection pre-seed — LIVE-VERIFIED 2026-06-11 against dev container; bash loop hits all six slug endpoints, every one returns `200` + standard OR list envelope with `results:[]`
- [x] Seed produces exactly the three documented rules and is idempotent on re-run — covered by `ArchiefEdepotSeedDataServiceTest::testSeedIsIdempotent`
