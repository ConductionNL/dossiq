# Tasks: woo-request-corpus-collection

Wave 2. Rows 19.1, 19.2, 19.3, 19.4 and 19.18. Decision D1. Kind: code. Build rules: `openspec/woo-build-rules.md`.

**Do not start before** `woo-requests-gather-documents-from-sources` is merged on `development`:
this change amends its three endpoints. Check its tasks and the routes
(`GET /woo/sources`, `POST /woo/sources/search`, `POST /woo/sources/add`) on `development`; if they
are not there, stop and say so. A test marked **fails today** must be run on `origin/development`
first and seen red. Build every OpenRegister double from the real class signatures.

## 1. The search plan

- [x] 1.1 Add schemas `wooSearchPlan` and `wooRequestConfiguration` in
  `lib/Settings/register.d/86-woo-corpus.json` (one plan per case, `case` uuid facetable), with the
  REQ-WRC-001 and REQ-WRC-005 properties, and bump the register version. A plan without
  `recordedAt` is a draft and counts as no plan (REQ-WRC-001).
  - unit `tests/Unit/Settings/WooCorpusSchemasTest.php` `testThePlanDeclaresEveryKey`.
  - done: `lib/Settings/register.d/86-woo-corpus.json` (also `wooExclusion`, `wooCollectionQuery`, `case.wooStartFrom`), register 0.20.23; `tests/Unit/Settings/WooCorpusSchemasTest.php`.
- [x] 1.2 Refuse search and add without a recorded plan, in the gather controller, before any
  source is called (REQ-WRC-001).
  - **fails today**: `tests/Unit/Controller/WooSourcesControllerTest.php`
    `testSearchWithoutAPlanAnswers409`, `testAddWithoutAPlanAnswers409`,
    `testADraftPlanCountsAsNone`.
  - done: `WooSourcesController::search/add` via `WooSearchPlans::recorded()`; `WooSourcesControllerTest::testSearchWithoutAPlanAnswers409`, `testAddWithoutAPlanAnswers409`, `testADraftPlanCountsAsNone`; `WooSearchPlansTest`.
- [x] 1.3 A `Search plan` section on the Woo case page and `src/dialogs/WooSearchPlanDialog.vue` to
  record or change it; a history tab that reads the plan's OpenRegister audit trail (REQ-WRC-001).
  - vitest `src/dialogs/__tests__/WooSearchPlanDialog.spec.js` `testCustodiansSystemsPeriodAndTermsAreRequired`.
  - Live check after merge: change the plan once, read `/audit-trails` for it, record the entry.
  - done: header action `woo-search-plan` opens `src/dialogs/WooSearchPlanDialog.vue` with the plan and its audit-trail history (the plan's place on the case page is the dialog, as board DqZaakDialogen draws it); `tests/vitest/wooSearchPlanDialog.spec.js`. Live audit-trail check owed (live pass, decision 139).

## 2. Custodian and system on each document

- [x] 2.1 The add requires `custodian` per pick (one of the plan's custodians, else 422 for that
  pick only) and writes `provenance.custodian` and `provenance.sourceSystem` (REQ-WRC-002).
  - **fails today**: `tests/Unit/Controller/WooSourcesControllerTest.php`
    `testAPickWithoutAPlanCustodianIsRefusedAlone`, `testProvenanceCarriesCustodianAndSystem`.
  - Validate the written projection with `tests/Support/RealSchemaValidator`:
    `testTheProvenanceValidatesAgainstTheDocumentSchema`.
  - done: `WooGatherAdd`; `WooGatherAddTest::testAPickWithoutAPlanCustodianIsRefusedAlone`, `testProvenanceCarriesCustodianAndSystem` (validates against the real schema).
- [x] 2.2 Add `WooCollectionController::report(string $id)` on GET `/api/cases/{id}/woo/collection`
  with a case read guard; answers per custodian and per system, zeros included (REQ-WRC-002,
  REQ-WRC-003).
  - unit `tests/Unit/Woo/WooCollectionReportTest.php` `testAPlannedCustodianWithNothingShowsZero`,
    `testVolumesAreSummedPerCustodian`.
  - Through the caller: `tests/Unit/Controller/WooCollectionControllerTest.php`
    `testTheReportRefusesAUserWithoutCaseAccess`.
  - done: `lib/Woo/WooCollection.php`, `lib/Controller/WooCollectionController.php`; `tests/Unit/Woo/WooCollectionReportTest.php`, `tests/Unit/Controller/WooCollectionControllerTest.php`.

## 3. Exclusions and reconciliation

- [x] 3.1 Add schema `wooExclusion` (REQ-WRC-003). The add hashes each pick before writing and
  records a matching hash as a `duplicate` exclusion instead of adding it. An unreadable pick is
  recorded as `unreadable` (REQ-WRC-003).
  - **fails today**: `testADuplicateIsListedNotAdded`, `testAnUnreadablePickIsRecorded` in
    `WooSourcesControllerTest`.
  - done: `WooGatherAdd::duplicateOf()`/`recordUnreadable()`; `WooGatherAddTest::testADuplicateIsListedNotAdded`, `testAnUnreadablePickIsRecorded` (the tests sit on the service the controller calls).
- [x] 3.2 POST `/api/cases/{id}/woo/documents/{documentRef}/exclude` with `{reason, note}`, refused
  with 409 when the document already has an assessment. The excluded document leaves
  `WOODocumentAssessmentService::getOutstanding()` (REQ-WRC-003).
  - **fails today**: `tests/Unit/Service/WOODocumentAssessmentServiceTest.php`
    `testAnExcludedDocumentIsNotOutstanding`.
  - Through the caller: `tests/Unit/Controller/WooCollectionControllerTest.php`
    `testExcludingAnAssessedDocumentAnswers409`.
  - done: `WooCollection::exclude()`, `WOODocumentAssessmentService::getOutstanding()`; `WOODocumentAssessmentServiceTest::testAnExcludedDocumentIsNotOutstanding` (red before), `WooCollectionControllerTest::testExcludingAnAssessedDocumentAnswers409`.
- [x] 3.3 The report answers `{arrived, assessed, excluded, outstanding}` and lists the exclusions
  (REQ-WRC-003).
  - unit `WooCollectionReportTest::testArrivedReconcilesWithReviewedAndExcluded` with the twelve
    candidate fixture of the scenario.
  - done: `WooCollectionReportTest::testArrivedReconcilesWithReviewedAndExcluded`.

## 4. Saved, re-runnable queries

- [x] 4.1 Add schema `wooCollectionQuery`. Every search through `/woo/sources/search` stores one. The
  platform sources the dialog searches directly (unified search, per the gather design D-2) are
  stored by the dialog through a POST `/api/cases/{id}/woo/collection/queries` call with the same
  keys (REQ-WRC-004).
  - unit `tests/Unit/Woo/WooCollectionQueryTest.php` `testASearchIsStoredWithItsResultKeys`.
  - vitest on the gather dialog: `testAPlatformSearchIsRecordedAsAQuery`.
  - done: `lib/Woo/WooCollectionQueries.php`; `tests/Unit/Woo/WooCollectionQueryTest.php`; the dialog stores platform searches (`gatherDocumentsDialog.spec.js` `testAPlatformSearchIsRecordedAsAQuery`).
- [x] 4.2 POST `/api/cases/{id}/woo/collection/queries/{queryId}/rerun` runs the stored query as the
  caller and marks new rows (REQ-WRC-004).
  - **fails today**: `WooCollectionQueryTest::testARerunMarksOnlyNewRows`.
  - Through the caller: `WooCollectionControllerTest::testAColleagueCanRerunAQuery` with a second
    user who has case read access.
  - done: `WooCollectionQueryTest::testARerunMarksOnlyNewRows`, `WooCollectionControllerTest::testAColleagueCanRerunAQuery`.

## 5. Starting from an earlier request

- [x] 5.1 Starting a Woo case with `startFrom` (a Woo case uuid) copies the configuration into the
  new case and a draft plan, and sets `copiedFrom`. Wire it in the case creation path the Woo case
  page and `WooRequestIntake` share, not in a second create path (REQ-WRC-005).
  - **fails today**: `tests/Unit/Woo/WooRequestConfigurationTest.php`
    `testANewRequestCopiesTheLastConfigurationWithoutThePeriod`, `testEveryPresentKeyIsCopied`.
  - done: `case.wooStartFrom` and `lib/Listener/WooStartFromListener.php` on OpenRegister's case creation (the path the case page and `WooRequestIntake` share); `tests/Unit/Woo/WooSearchPlansTest.php` `testANewRequestCopiesTheLastConfigurationWithoutThePeriod`, `testEveryPresentKeyIsCopied`; `tests/Unit/Listener/WooStartFromListenerTest.php`; `WooRequestIntakeTest::testARequestThatStartsFromAnEarlierOneNamesIt`.
- [ ] 5.2 Named templates: if openregister's `records-saved-templates` is merged on `development`,
  offer the `wooRequestConfiguration` templates in the start dialog and test
  `testANewRequestStartsFromANamedTemplate`. If it is not merged, do not build a dossiq template
  store; leave this box open and say so in the PR body (REQ-WRC-005).
  - not done: openregister `records-saved-templates` is not built on development. Per decision 156 its build is queued in lane L9's STATE as the dependency to build next; this box stays open until it lands.

## 6. End to end and live

- [ ] 6.1 e2e `tests/e2e/woo-corpus.spec.ts`: record a plan, gather with a custodian, add a duplicate
  and see it listed, exclude one document, read the reconciliation, re-run the query, then start a
  new request from this one. Cite REQ-WRC-001 to REQ-WRC-005.
  - spec written; the run is owed (live pass, decision 139).
- [ ] 6.2 Live check after merge on the dev instance: one Woo case through the same path. Record the
  collection report.

## 7. Verify and deliver

- [ ] 7.1 `TMPDIR` set to a sibling directory beside the clone.
- [ ] 7.2 While building, run `./vendor/bin/phpunit -c phpunit-unit.xml --no-coverage --filter` on
  the touched classes and `npx vitest run` on the touched specs. Judge PHPUnit by the `Tests:` line.
- [ ] 7.3 Before push, once: `COMPOSER_PROCESS_TIMEOUT=0 composer check:strict`, then `npm run lint`,
  `npm run format`, `npm run check:l10n-js`, `npm run check:schema-l10n` and
  `npm run check:manifest`, plus any other leg `code-quality.yml` requires. Then hydra's
  `scripts/run-hydra-gates.sh --base origin/development`; count the gates that ran.
- [ ] 7.4 Project coverage of the added statements. When no coverage driver (xdebug or pcov) is available, take the base percentages from `development`'s last green push run, intersect its clover uncovered lines with the lines this branch adds, and say in the PR body that the number is projected, not measured.
- [ ] 7.5 One PR, `--base development`. Merge, never rebase. No `Co-Authored-By`. Done means merged on
  `development` with CI green. The five rows then read `yes` (build); 19.18 reads `yes` for "from
  the last one" and partial for "from a named template" until 5.2 is done. `production` only with a
  store release.
