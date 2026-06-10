# Tasks — Member 04: Decidesk Import (code)

> **Build status (hydra audit).** Mostly greenfield. Dev has only lib/Service/MandaatValidationService.php::validate() (191 lines, single-method) + lib/Controller/MandaatController.php (124 lines). The MandateringsBesluit/Mandaat/OrganisatieRol/MedewerkerRolToewijzing schemas, full authorization+escalation engines, Decidesk import, case+decision integration, temporal+conflict resolver, and admin/user UI are not on dev. Tasks stay [ ] as genuine forward work; the existing slim MandaatValidationService is the foundation to grow on.

Sourced from giant task 7 (Decidesk Import Service).

## 1. Import Service

- [ ] Implement `DecideskImportService.importFromDecidesk(decidesk_uuid)` — fetch besluit + attachment, extract metadata
- [ ] Download attachment; parse Excel (.xlsx via PhpSpreadsheet) and CSV
- [ ] Extract rows {mandaatNummer, omschrijving, rolNaam, plafondBedrag, subdelegatie, wettelijkeGrondslag}
- [ ] Validate all rolNaam resolve to OrganisatieRol (error if missing)
- [ ] Create MandateringsBesluit (concept) + one Mandaat (concept) per row
- [ ] Skip empty/header rows; flatten merged cells; support optional columns

## 2. Diff + Approval

- [ ] Implement diff generation vs prior version by mandaatNummer (NEW/CHANGED/REMOVED/UNCHANGED, field-level)
- [ ] Return {mandateringsBesluitId, totalMandaten, newCount, changedCount, removedCount, diff}
- [ ] Implement approval flow: besluit → vastgesteld, mandaten → active, prior besluit → vervallen (vervalDatum)

## 3. Controller + Tests

- [ ] Create `MandaatImportController`: `POST /api/mandate/import` (preview), `POST /api/mandate/import/{importId}/approve` — admin/Legal-Affairs guarded, registered in appinfo/routes.php
- [ ] Test import with sample Excel; validation (missing role → error); diff generation; idempotency
