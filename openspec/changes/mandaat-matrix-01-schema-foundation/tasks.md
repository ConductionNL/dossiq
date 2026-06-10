# Tasks — Member 01: Schema Foundation (config)

Sourced from giant tasks 1–2 (Data Model and Schemas; Seed Data).

## 1. Schemas

- [~] Define MandateringsBesluit schema (besluitNummer, besluitNaam, status, dates, etc.) — deferred to downstream cycle / fleet-wide adoption (handoff)
- [~] Define Mandaat schema with voorwaarden (JSON: plafond, subdelegatie, etc.) — deferred to downstream cycle / fleet-wide adoption (handoff)
- [~] Define OrganisatieRol schema with hierarchical parentRolId — deferred to downstream cycle / fleet-wide adoption (handoff)
- [~] Define MedewerkerRolToewijzing schema with toewijzingType enum — deferred to downstream cycle / fleet-wide adoption (handoff)
- [~] Define MandaatGebruik schema with JSON snapshot fields (rolOpMomentVanBesluit, gebruikteVoorwaarden) — deferred to downstream cycle / fleet-wide adoption (handoff)
- [~] Define MandaatEscalatie schema with escalatieReden enum — deferred to downstream cycle / fleet-wide adoption (handoff)
- [~] Validate schema JSON against OpenAPI 3.0 + x-openregister — deferred to downstream cycle / fleet-wide adoption (handoff)
- [~] Update procest_register.json to include the 6 new schemas with relations — deferred to downstream cycle / fleet-wide adoption (handoff)
- [~] Create register/upgrade step in appinfo/info.xml to register schemas on install — deferred to downstream cycle / fleet-wide adoption (handoff)
- [~] Test schema creation (register returns schema UUID for each) — deferred to downstream cycle / fleet-wide adoption (handoff)

## 2. Seed Data

- [~] Create mandate-matrix-seed.json with seed data (role IDs populated by repair step) — deferred to downstream cycle / fleet-wide adoption (handoff)
- [~] Create MandaatMatrixSeedRepairStep.php; register it in appinfo/info.xml — deferred to downstream cycle / fleet-wide adoption (handoff)
- [~] In repair(): create OrganisatieRol → MedewerkerRolToewijzing → MandateringsBesluit → Mandaat via ObjectService, capturing UUIDs — deferred to downstream cycle / fleet-wide adoption (handoff)
- [~] Reference captured UUIDs across dependent records (iterative seeding) — deferred to downstream cycle / fleet-wide adoption (handoff)
- [~] Integration test: all seed records exist with correct cross-references — deferred to downstream cycle / fleet-wide adoption (handoff)
- [~] Integration test: idempotency (run twice; counts stay 7/5/2/4, no duplicates) — deferred to downstream cycle / fleet-wide adoption (handoff)
