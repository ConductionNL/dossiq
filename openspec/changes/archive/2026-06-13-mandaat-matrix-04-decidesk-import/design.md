# Design — Member 04: Decidesk Import (code)

## Scope

`DecideskImportService` + `MandaatImportController`. Writes `MandateringsBesluit` + `Mandaat`
(member 01) via ObjectService (ADR-001). Reads OrganisatieRol for role validation.

## Service contract

`DecideskImportService.importFromDecidesk(decidesk_uuid)`:
- call decidesk REST API for besluit metadata + attachment;
- download attachment; if Excel parse via PhpSpreadsheet, if CSV parse rows;
- extract rows `{mandaatNummer, omschrijving, rolNaam, plafondBedrag, subdelegatie, wettelijkeGrondslag}`;
- validate every `rolNaam` resolves to an OrganisatieRol (error "Role '…' not found" otherwise);
- create MandateringsBesluit `status: "concept"` + one Mandaat per row `status: "concept"`;
- generate diff vs prior MandateringsBesluit by mandaatNummer (NEW / CHANGED / REMOVED / UNCHANGED);
- return `{mandateringsBesluitId, totalMandaten, newCount, changedCount, removedCount, diff}`.

Table parsing: support `.xlsx` + CSV; expected columns Nummer, Omschrijving, Rol, PlafondBedrag,
Subdelegatie, WettelijkeGrondslag (+ optional Beschrijving, Opmerkingen); skip empty/header rows;
flatten merged cells.

Approval finalisation: MandateringsBesluit → "vastgesteld"; all its Mandaat → "active"; prior
MandateringsBesluit → "vervallen" with `vervalDatum` = day before the new `inwerkingtreding`.

## API design (ADR-002 / ADR-016)

Routes in `appinfo/routes.php`:
- `POST /api/mandate/import` (payload `{decidesk_uuid}`) → returns import preview (diff)
- `POST /api/mandate/import/{importId}/approve` → finalises

## Security (ADR-005)

Import endpoints are admin/Legal-Affairs-only (NC SecurityMiddleware admin default unless an
explicit non-admin posture is declared). Concept records cannot affect authorization until
approved (only "active" Mandaten are queried by member 02). The decidesk attachment is fetched
server-side; no client-supplied file path is trusted.
