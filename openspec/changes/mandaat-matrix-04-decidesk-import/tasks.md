# Tasks — Member 04: Decidesk Import (code)

Sourced from giant task 7 (Decidesk Import Service).

## 1. Import Service

- [~] Implement `DecideskImportService.importFromDecidesk(decidesk_uuid)` — fetch besluit + attachment, extract metadata — deferred to downstream cycle / fleet-wide adoption (handoff)
- [~] Download attachment; parse Excel (.xlsx via PhpSpreadsheet) and CSV — deferred to downstream cycle / fleet-wide adoption (handoff)
- [~] Extract rows {mandaatNummer, omschrijving, rolNaam, plafondBedrag, subdelegatie, wettelijkeGrondslag} — deferred to downstream cycle / fleet-wide adoption (handoff)
- [~] Validate all rolNaam resolve to OrganisatieRol (error if missing) — deferred to downstream cycle / fleet-wide adoption (handoff)
- [~] Create MandateringsBesluit (concept) + one Mandaat (concept) per row — deferred to downstream cycle / fleet-wide adoption (handoff)
- [~] Skip empty/header rows; flatten merged cells; support optional columns — deferred to downstream cycle / fleet-wide adoption (handoff)

## 2. Diff + Approval

- [~] Implement diff generation vs prior version by mandaatNummer (NEW/CHANGED/REMOVED/UNCHANGED, field-level) — deferred to downstream cycle / fleet-wide adoption (handoff)
- [~] Return {mandateringsBesluitId, totalMandaten, newCount, changedCount, removedCount, diff} — deferred to downstream cycle / fleet-wide adoption (handoff)
- [~] Implement approval flow: besluit → vastgesteld, mandaten → active, prior besluit → vervallen (vervalDatum) — deferred to downstream cycle / fleet-wide adoption (handoff)

## 3. Controller + Tests

- [~] Create `MandaatImportController`: `POST /api/mandate/import` (preview), `POST /api/mandate/import/{importId}/approve` — admin/Legal-Affairs guarded, registered in appinfo/routes.php — deferred to downstream cycle / fleet-wide adoption (handoff)
- [~] Test import with sample Excel; validation (missing role → error); diff generation; idempotency — deferred to downstream cycle / fleet-wide adoption (handoff)
