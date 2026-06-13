# Tasks: related-case-linking

## Deduplication Check

- [x] **DC01**: Confirm `deelzaak-support` remains parent-child only (its REQ-DZS-001…008 cover `parentCase`/`subCaseTypes`) and that no other spec/change defines `relatedCases` behaviour — `grep -ri 'relatedCases\|relevanteAndereZaken\|aardRelatie' openspec/`. Document findings.
- [x] **DC02**: Verify the `relatedCases` field exists on the `case` schema in `lib/Settings/procest_register.json` and is `visible: true`; check whether any code already reads/writes it before adding the service.

> **Findings (DC01/DC02):** `deelzaak-support` is parent-child only (its spec scopes to `parentCase`/`subCaseTypes`; no `relatedCases`/`aardRelatie` behaviour). No other spec/change defines `relatedCases` semantics — the grep hits in case-management/openregister-integration/adr-000 are the field DECLARATION (table row, ZGW name `relevanteAndereZaken`) only. The `relatedCases` field exists on the `case` schema as a JSON-encoded **string** (`type: string`, `visible: false`, same family as `statusHistory`/`activity`/`geometry`) — NOT `visible: true` as the task assumed and NOT an array type. No code read/wrote it before this change (the only prior `relevanteAndereZaken` reference was inbound URL validation in `ZgwZrcRulesService`, zrc-011). Consequences: T01 documents the element shape in the field `description` (additive, no type change); `CaseRelationService` stringifies on write and parses on read; ZGW outbound is built in `ZrcController` (not the declarative mapping, which cannot synthesise `[{url,aardRelatie}]` from a JSON string).

## Schema & Configuration

- [x] **T01**: Specify the `relatedCases` element shape on the `case` schema: array of objects `{caseId (uuid, required), aardRelatie (enum: vervolg|onderwerp|bijdrage, required), toelichting (string, optional)}` with enum constraint, in `lib/Settings/procest_register.json`. No new schema, no new config keys.

## Backend Services

- [x] **T02**: Create `lib/Service/CaseRelationService.php` — `addRelation(caseId, targetId, aardRelatie, ?toelichting, actorId)` (symmetric two-sided write; guards: self-relation, duplicate `{caseId, aardRelatie}` pair, existing direct hoofdzaak/deelzaak hierarchy link, OR-RBAC read access to BOTH cases), `removeRelation(...)` (two-sided), `cleanupForDeletedCase(caseId)` (remove counterpart entries on case deletion — hook into the existing case-deletion path next to the deelzaak orphan cleanup), `normalise(caseId)` (restore symmetry after direct field writes, used by the ZGW inbound path). Unit tests for every guard and the two-sided invariants.
- [x] **T03**: Endpoints — `POST /api/cases/{id}/relations`, `DELETE /api/cases/{id}/relations/{targetId}/{aardRelatie}` on the case controller (`#[NoAdminRequired]` + per-object OR RBAC guards); register routes in `appinfo/routes.php`.

## ZGW Mapping

- [x] **T04**: Extend the ZRC Zaak outbound mapping: `relatedCases[*]` → `relevanteAndereZaken: [{url, aardRelatie}]` via the existing URL-reference translation; emit `[]` when empty; never emit `toelichting`. Extend inbound zaak create/update: resolve `relevanteAndereZaken` URLs to local UUIDs (standard ZGW validation error on unresolvable URL) and route through `CaseRelationService` so guards + symmetry hold. Newman cases in `tests/integration/` for outbound shape, inbound write, and the rejection path.

## Frontend

- [x] **T05**: `src/views/cases/components/RelatedCasesSection.vue` — "Gerelateerde zaken" section on the case detail (manifest sidebarTab or inline section per the deelzaak precedent): list with direction-aware type label, title, status, toelichting, navigation; masked stub (case number only, no link) for OR-RBAC-unreadable targets; remove action.
- [x] **T06**: `src/modals/AddCaseRelationModal.vue` — case search picker, `aardRelatie` NcSelect (with inputLabel), optional toelichting, inline validation errors from the guard responses; section updates without reload on success.
- [x] **T07**: Dutch + English i18n for all new UI strings (English source keys).

## Verification Tasks

- [x] **V01**: Adding a relation from case A shows it on BOTH case details with direction-aware labels; removing from either side clears both.
- [x] **V02**: Guards verified — self-relation, duplicate pair, hierarchy-overlap (parent/sub-case), and no-read-access-to-target all rejected with clear errors; same target with a different `aardRelatie` accepted.
- [x] **V03**: Deleting a case removes its entries from all counterpart cases (no dangling references).
- [x] **V04**: Unreadable target renders masked (number + type only, no navigation) while readable targets navigate correctly.
- [x] **V05**: ZGW — `GET zaken/{uuid}` returns `relevanteAndereZaken` with absolute URLs + aardRelatie (and `[]` when none); inbound PATCH with a valid zaak URL creates the symmetric relation; unresolvable URL yields the standard ZGW validation error.
