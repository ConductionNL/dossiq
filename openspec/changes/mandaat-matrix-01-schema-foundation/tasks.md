# Tasks — Member 01: Schema Foundation (config)

> **Build status (hydra audit).** Mostly greenfield. Dev has only lib/Service/MandaatValidationService.php::validate() (191 lines, single-method) + lib/Controller/MandaatController.php (124 lines). The MandateringsBesluit/Mandaat/OrganisatieRol/MedewerkerRolToewijzing schemas, full authorization+escalation engines, Decidesk import, case+decision integration, temporal+conflict resolver, and admin/user UI are not on dev. Tasks stay [ ] as genuine forward work; the existing slim MandaatValidationService is the foundation to grow on.

Sourced from giant tasks 1–2 (Data Model and Schemas; Seed Data).

## 1. Schemas

- [ ] Define MandateringsBesluit schema (besluitNummer, besluitNaam, status, dates, etc.)
- [ ] Define Mandaat schema with voorwaarden (JSON: plafond, subdelegatie, etc.)
- [ ] Define OrganisatieRol schema with hierarchical parentRolId
- [ ] Define MedewerkerRolToewijzing schema with toewijzingType enum
- [ ] Define MandaatGebruik schema with JSON snapshot fields (rolOpMomentVanBesluit, gebruikteVoorwaarden)
- [ ] Define MandaatEscalatie schema with escalatieReden enum
- [ ] Validate schema JSON against OpenAPI 3.0 + x-openregister
- [ ] Update procest_register.json to include the 6 new schemas with relations
- [ ] Create register/upgrade step in appinfo/info.xml to register schemas on install
- [ ] Test schema creation (register returns schema UUID for each)

## 2. Seed Data

- [ ] Create mandate-matrix-seed.json with seed data (role IDs populated by repair step)
- [ ] Create MandaatMatrixSeedRepairStep.php; register it in appinfo/info.xml
- [ ] In repair(): create OrganisatieRol → MedewerkerRolToewijzing → MandateringsBesluit → Mandaat via ObjectService, capturing UUIDs
- [ ] Reference captured UUIDs across dependent records (iterative seeding)
- [ ] Integration test: all seed records exist with correct cross-references
- [ ] Integration test: idempotency (run twice; counts stay 7/5/2/4, no duplicates)
