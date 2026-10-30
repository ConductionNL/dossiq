# Tasks: complaints-are-a-case-type

## 1. Package

- [ ] 1.1 Verify Awb 9:11 (and 9:14 for the committee) on wetten.overheid.nl; cite the article text in `lib/Settings/packages/klachtbehandeling/package.json` `description`.
- [ ] 1.2 `package.json` (`id: klachtbehandeling`, `install: on-request`, parts case-type, flows, examples), `case-type.json` (case type, statuses, roles, result types, `processingDeadline: P6W`, extension P4W), `flows/klacht.json`, `examples/` with one complaint in flight.
- [ ] 1.3 The committee variant: a case type property (`advisoryCommittee: true`) switches the term to P10W through the term definitions, not through code.
- [ ] 1.4 `tests/integration/packages/klachtbehandeling.postman_collection.json`: install, start a case, read its term.

## 2. Surfaces

- [ ] 2.1 Saved view "Klachten" in `lib/Settings/case-type-views.json` (filter on the case type) and a menu entry in `src/menu-layout.json` that only shows when the package is installed.
- [ ] 2.2 Hearing: the generic session action on CaseDetail is offered for this case type (configuration on the case type, no complaint code).

## 3. Carry-over and retirement

- [ ] 3.1 Repair step `lib/Repair/CarryRecordsOntoCases.php` (generic: source schema, target case type and a field map as arguments) with the `complaint` map; `tests/Unit/Repair/CarryRecordsOntoCasesTest.php` (idempotent, unreadable source left in place and reported).
- [ ] 3.2 One release later: remove `lib/Service/ComplaintService.php` and the five complaint controllers (`ComplaintController`, `ComplaintAnalyticsController`, `ComplaintCategoryController`, `ComplaintDispositionController`, `ComplaintHearingController`), their routes in `appinfo/routes.php` and their tests; lower the gate 119 baseline.

## 4. Checks

- [ ] 4.1 `tests/e2e/complaint-case-type.spec.ts`: both scenarios of REQ-CMP-CT-01.
- [ ] 4.2 Live pass against board DqKlachten.
- [ ] 4.3 `openspec/parity/capabilities.json` row 2.53: built, evidence the package and the view.
