# Tasks — Member 04: Decidesk Import (code)

Sourced from giant task 7 (Decidesk Import Service).

## 1. Import Service

- [x] Implement `DecideskImportService.importFromDecidesk(decidesk_uuid)` — exists as `lib/Service/MandaatImportService.php::importFromDecidesk` (renamed during build to keep procest naming consistent with the other Mandaat* services)
- [x] Download attachment; parse Excel (.xlsx via PhpSpreadsheet) and CSV — `MandaatImportService::parseAttachment` switches on MIME; PhpSpreadsheet bound via composer
- [x] Extract rows {mandaatNummer, omschrijving, rolNaam, plafondBedrag, subdelegatie, wettelijkeGrondslag} — same method
- [x] Validate all rolNaam resolve to OrganisatieRol (error if missing) — `MandaatImportService::resolveRole` throws DomainException on miss; preview surfaces the error list
- [x] Create MandateringsBesluit (concept) + one Mandaat (concept) per row — `importFromDecidesk` writes both via ObjectService
- [x] Skip empty/header rows; flatten merged cells; support optional columns — `parseAttachment` handles all three

## 2. Diff + Approval

- [x] Implement diff generation vs prior version by mandaatNummer (NEW/CHANGED/REMOVED/UNCHANGED, field-level) — `MandaatImportService::diffAgainstPrior`
- [x] Return `{mandateringsBesluitId, totalMandaten, newCount, changedCount, removedCount, diff}` — `importFromDecidesk` shape
- [x] Implement approval flow — `MandaatImportService::approveImport` moves besluit → vastgesteld, mandaten → active, prior → vervallen with vervalDatum

## 3. Controller + Tests

- [x] Create `MandaatImportController`: `POST /api/mandate/import` (preview), `POST /api/mandate/import/{importId}/approve` — `MandaatMatrixController::importPreview` + `importApprove` (routes 493-494); admin guard via `requireAuthenticated()` + role check
- [x] Test import with sample Excel; validation; diff; idempotency — `tests/Unit/Service/MandaatImportServiceTest.php` covers parse/diff/approve/idempotency
