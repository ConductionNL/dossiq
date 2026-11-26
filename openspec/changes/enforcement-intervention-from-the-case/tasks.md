# Tasks: enforcement-intervention-from-the-case

## 1. Configuration

- [x] 1.1 Case type property `adviceDecisionTable` (stored decision-table id or slug) on the case type schema in `lib/Settings/register.d/` (the file that declares `caseType`), with a migration-free default of empty.
- [x] 1.2 `lib/Settings/packages/vth/case-type.json`: set `adviceDecisionTable` on the handhaving case types to the `lhs-intervention` table; bump the package version.

## 2. Backend

- [x] 2.1 Generic routes `POST /api/decision-advice/recommend` and `POST /api/decision-advice/override` in `appinfo/routes.php` on a controller named for what it does (`DecisionAdviceController`), reading the inputs from the table's declared inputs instead of the fixed `severity`/`behaviour`/`actorType` params.
- [x] 2.2 `/api/lhs/recommend` and `/api/lhs/override` forward to the generic routes for one release; note the removal in the changelog.
  - Decision (25 Nov 2026, builder under the 22 Nov delegation): the old routes are NOT forwarded. They keep answering unchanged for one release and are removed in the next; CHANGELOG.md `Deprecated` names the successors. A forward would change the contract under an external client: the generic routes take the table's declared inputs instead of `severity`/`behaviour`/`actorType`, answer a `decisionAdvice` object instead of an `lhsRecommendation` row, and carry no manager check on an escalation, so a forwarded override would silently drop that guard. No dossiq screen calls the old routes (the case page uses `src/services/decisionAdviceApi.js`), so nothing in the app moves.
- [x] 2.3 `tests/Unit/Controller/DecisionAdviceControllerTest.php`: recommend, accept, override without reason refused (400), case type without table refused (409).

## 3. Case page

- [x] 3.1 `src/dialogs/DecisionTableAdviceDialog.vue`: inputs generated from the table's input definitions, the recommendation, accept or override with reason (NcDialog, own file, labelled selects).
- [x] 3.2 `src/manifest.json` CaseDetail `headerActions`: `advice-from-decision-table` (`open-modal`, target the dialog), `visibleWhen: "@context.caseType.adviceDecisionTable"`.
- [x] 3.3 The decisions widget on CaseDetail lists the stored advice objects (relation from the case).
- [x] 3.4 Move the `LhsRecommendations` pages out of settings into the case page's related list, or remove them if 3.3 covers them; no menu entry for a procedure.

## 4. Tests and checks

- [x] 4.1 `tests/e2e/decision-table-advice.spec.ts`: the three scenarios of REQ-LHS-CASE-01.
  - Written, runs in nightly (`tests/e2e/decision-table-advice.spec.ts`, #6685); not run here, the live pass owns it.
- [x] 4.2 Newman: `tests/integration/packages/lhs-intervention.postman_collection.json` gains a recommend and an override call on an example handhavingszaak.
  - Written, runs in the packages workflow: six `advice on a case:` requests file a handhavingszaak (case type `9e4fe525`, which names `dossiq.lhs-intervention`) through the OpenRegister objects API. They then read its advice form (the table, inputs `behaviour` and `consequence`, outcome Waarschuwen) and ask advice for cell A:2 (Waarschuwen). Another outcome without a reason is refused (400). The same outcome with a reason is kept, with `accepted` false and the advice unchanged. A second decision is refused (409). Not run against a live instance in this build. The case is left in place, since cases are archival.
- [ ] 4.3 Live pass against board DqHandhaving.
- [x] 4.4 `openspec/parity/capabilities.json` row 7.10: built, evidence the header action and dialog, `reachedOn` CaseDetail.
