# Tasks: enforcement-intervention-from-the-case

## 1. Configuration

- [ ] 1.1 Case type property `adviceDecisionTable` (stored decision-table id or slug) on the case type schema in `lib/Settings/register.d/` (the file that declares `caseType`), with a migration-free default of empty.
- [ ] 1.2 `lib/Settings/packages/vth/case-type.json`: set `adviceDecisionTable` on the handhaving case types to the `lhs-intervention` table; bump the package version.

## 2. Backend

- [ ] 2.1 Generic routes `POST /api/decision-advice/recommend` and `POST /api/decision-advice/override` in `appinfo/routes.php` on a controller named for what it does (`DecisionAdviceController`), reading the inputs from the table's declared inputs instead of the fixed `severity`/`behaviour`/`actorType` params.
- [ ] 2.2 `/api/lhs/recommend` and `/api/lhs/override` forward to the generic routes for one release; note the removal in the changelog.
- [ ] 2.3 `tests/Unit/Controller/DecisionAdviceControllerTest.php`: recommend, accept, override without reason refused (400), case type without table refused (409).

## 3. Case page

- [ ] 3.1 `src/dialogs/DecisionTableAdviceDialog.vue`: inputs generated from the table's input definitions, the recommendation, accept or override with reason (NcDialog, own file, labelled selects).
- [ ] 3.2 `src/manifest.json` CaseDetail `headerActions`: `advice-from-decision-table` (`open-modal`, target the dialog), `visibleWhen: "@context.caseType.adviceDecisionTable"`.
- [ ] 3.3 The decisions widget on CaseDetail lists the stored advice objects (relation from the case).
- [ ] 3.4 Move the `LhsRecommendations` pages out of settings into the case page's related list, or remove them if 3.3 covers them; no menu entry for a procedure.

## 4. Tests and checks

- [ ] 4.1 `tests/e2e/decision-table-advice.spec.ts`: the three scenarios of REQ-LHS-CASE-01.
- [ ] 4.2 Newman: `tests/integration/packages/lhs-intervention.postman_collection.json` gains a recommend and an override call on an example handhavingszaak.
- [ ] 4.3 Live pass against board DqHandhaving.
- [ ] 4.4 `openspec/parity/capabilities.json` row 7.10: built, evidence the header action and dialog, `reachedOn` CaseDetail.
