# Tasks: woo-review-triage

Wave 3. Rows 19.7, 19.8, 19.9, 19.10, 19.11 and 19.17. Decision D1. Kind: code. Build rules: `openspec/woo-build-rules.md`.

**Do not start before** `woo-request-corpus-collection` and `woo-request-scoped-access` are merged
on `development`. Woo records do not use `x-openregister-hierarchy` (see section 6). A test marked **fails today** must be run on
`origin/development` first and seen red. Build OpenRegister doubles from the real class signatures,
and construct the real OpenRegister event classes in listener tests.

If the change is too large for one PR, sections 1 to 3 (relevance, rules, batches) are the first
PR, sections 4 to 6 the second.

## 1. Relevance apart from the verdict

- [ ] 1.1 Add schema `wooDocumentReview` in `lib/Settings/register.d/87-woo-review.json` with the
  REQ-WRT-001 properties; create one per document at the gather add and for documents already on a
  Woo case through a repair step (idempotent). Bump the register version (REQ-WRT-001).
  - unit `tests/Unit/Repair/CreateWooDocumentReviewsTest.php` `testEveryDocumentGetsOneReview`,
    `testASecondRunCreatesNothing`.
  - Built: schemas in `87-woo-review.json` (register 0.20.23, app version bumped), slug map and config
    keys, `lib/Woo/WooDocumentReviews.php`, repair `lib/Repair/CreateWooDocumentReviews.php` with
    `CreateWooDocumentReviewsTest` (`testEveryAssessedDocumentGetsOneInScopeReview`, `testASecondRunCreatesNothing`).
    The repair marks documents that already carry a verdict in scope (a verdict is a reviewer's judgement that
    the document is about the request); without that, every existing Woo case would block on unmarked documents.
    Open: "create one per document at the gather add" belongs in the add that lane L9 builds
    (`woo-requests-gather-documents-from-sources`). Until then a document without a review reads as unmarked,
    which is the same state the add would write.
- [x] 1.2 POST `/api/cases/{id}/woo/documents/{documentRef}/relevance` with `{relevance}`, case
  mutation guard. `getOutstanding()` skips out-of-scope documents. The case summary reports the
  three counts (REQ-WRT-001).
  - **fails today**: `tests/Unit/Service/WOODocumentAssessmentServiceTest.php`
    `testAnOutOfScopeDocumentNeedsNoVerdict`, `testUnmarkedDocumentsAreOutstanding`.
  - Through the caller: `tests/Unit/Controller/WooReviewControllerTest.php`
    `testTheSummaryReportsRelevanceBesideVerdicts` (the ten-document fixture).
  - `allDocumentsAssessed()` now means every in-scope document has a verdict and none is unmarked.
    Test: `testTheDecisionWaitsForUnmarkedDocuments`.
  - Built: `WooReviewController` (relevance, summary; mutation and read guard through `CaseAccessGuard`),
    `lib/Woo/WooReviewSummary.php`, `WooDocumentReviews::outstanding()` behind `getOutstanding()`. The three
    named service tests failed first (class absent); `WooReviewControllerTest` also covers the overturned rule
    and `testAUserWithoutAGrantOnTheCaseIsRefused` (6.3 for this controller).

## 2. Rules

- [ ] 2.1 Add schema `wooTriageRule` and `lib/Woo/WooTriageRules.php` with
  `apply(string $caseId): array` per REQ-WRT-002. Text conditions read the document's extracted
  text through OpenRegister's text extraction where the document has it; a document without text
  never matches a text condition (REQ-WRT-002).
  - **fails today**: `tests/Unit/Woo/WooTriageRulesTest.php` `testARuleSetsAsideNewsletters`,
    `testTheFirstMatchingRuleDecides`, `testARuleNeverChangesAReviewersMarking`,
    `testAnOverturnRecordsTheRule`, `testADocumentWithoutTextNeverMatchesATextCondition`.
  - Through the caller: `WooReviewControllerTest::testApplyAnswersTheCountPerRule`.
- [ ] 2.2 A `Triage rules` section on the Woo case page with the rules, their conditions and their
  counts, and `src/dialogs/WooTriageRuleDialog.vue` to add or edit one (REQ-WRT-002).
  - vitest `src/dialogs/__tests__/WooTriageRuleDialog.spec.js` `testAnEffectIsRequired`.

## 3. Batches

- [ ] 3.1 Add schema `wooReviewBatch` and POST `/api/cases/{id}/woo/batches`. It writes the batch on
  each review, refuses a document in another open batch, and creates a dossiq task through the
  existing task service, assigned to the reviewer (REQ-WRT-003).
  - **fails today**: `tests/Unit/Woo/WooReviewBatchesTest.php` `testEachBatchGetsATaskForItsReviewer`,
    `testADocumentCannotBeInTwoOpenBatches`, `testABatchByFilterTakesTheMatchingDocuments`.
  - Through the caller: `WooReviewControllerTest::testCreateBatchRefusesWithoutMutationAccess`.
  - Built: `lib/Woo/WooReviewBatches.php` (select, taken, create, forCase) with `WooReviewBatchesTest`
    (the three named tests plus closed-batch release, unknown filter, incomplete batch, engine refusal, progress);
    `WooReviewController::createBatch` (201, 409 with `taken` naming the batch, mutation guard) and `::batches`
    (read guard, progress assessed of total), routes `GET|POST /api/cases/{id}/woo/batches`. The task goes through
    `EngineTaskGateway::mirrorImport()` with `metadata.dossiq.kind` `woo-review-batch`.
    Open: the filter takes `rule` today. `custodian` and `sourceSystem` are refused (`woo-batch-filter-unknown`)
    until the collected documents carry them (lane L9, woo-request-corpus-collection).
- [ ] 3.2 A `Batches` section on the case page with assignee and progress (REQ-WRT-003).
  - `npm run check:manifest` exits 0.

## 4. Review depth

- [ ] 4.1 Add `reviewDepth` to `wooRequestConfiguration`. Batch creation sets `depth` and
  `pagesRequired` per review, sampling with a seeded generator whose seed is stored. Confirm that
  REQ-WRC-005's copy carries `reviewDepth` (REQ-WRT-004).
  - **fails today**: `tests/Unit/Woo/WooReviewDepthTest.php` `testASampleDrawsItsSizeWithARecordedSeed`,
    `testTheSameSeedDrawsTheSamePages`, `testAnUnnamedTypeIsEveryPage`.
  - `tests/Unit/Woo/WooRequestConfigurationTest.php` `testReviewDepthIsCopied`.

## 5. Every page seen

- [ ] 5.1 POST `/api/cases/{id}/woo/documents/{documentRef}/pages-seen`, case read guard (a reviewer
  reads, and seeing is reading), appending pages with reviewer and time. The dossiq document view
  on a Woo case calls it as pages are displayed (REQ-WRT-005).
  - unit `WooReviewControllerTest::testPagesSeenAreAppendedWithTheReviewer`.
  - vitest on the viewer component: `testEachDisplayedPageIsReportedOnce`.
- [ ] 5.2 Refuse a verdict while required pages are unseen: in `WOODocumentAssessmentService::validate()`
  for `bulkAssess`, and in a listener on OpenRegister's object updating and creating events for
  `wooDocumentAssessment` (direct API writes) (REQ-WRT-005).
  - **fails today**: `testAVerdictSetWithoutOpeningIsRefused` in
    `tests/Unit/Listener/WooPagesSeenGuardTest.php`, with the real event class, and
    `testBulkAssessRefusesUnseenPages` in `WOOAssessmentControllerTest`.
- [ ] 5.3 `assembleDecision()` and `publish()` refuse with 409 naming documents and pages (REQ-WRT-005).
  - **fails today**: `WooPublicationServiceTest::testPublishWaitsForTheLastPage`,
    `WOODecisionServiceTest::testTheDecisionWaitsForTheLastPage`.

## 6. The request as an authorisation container

Read `dossiq/woo-request-scoped-access` first: this section extends its `WooAssessmentAccess` and
does not start before that change is merged. Do not declare `x-openregister-hierarchy` on any Woo
record. OpenRegister refuses a parent that references another schema
(`HierarchyAnnotationValidator`, `hierarchy.foreign-reference`) and the schema then fails to import.

- [ ] 6.1 Give the seven schemas of REQ-WRT-006 (those this change adds, and `wooSearchPlan`,
  `wooExclusion`, `wooCollectionQuery` and `wooDeliveredSet` from earlier changes) the private
  authorization block of REQ-WRT-006, with no `x-openregister-hierarchy` key, and bump the register
  version (REQ-WRT-006).
  - **fails today**: `tests/Unit/Settings/WooChildSchemasTest.php`
    `testEveryWooChildIsPrivateAndDeclaresNoHierarchy` and `testNoWooChildIsReadableByAllUsers`,
    reading the merged register the importer reads.
  - Newman `tests/newman/woo-review-triage.postman_collection.json`, folder `schemas`: read each of
    the seven schemas back and assert `authorization.scope` is `private` and no hierarchy key is set.
- [ ] 6.2 Extend `WooAssessmentAccess::reconcile()` with the grant table of REQ-WRT-006, and call it
  after the create, in the same request, from every route that creates one of the seven: the gather
  add, the search plan save, the query record, the exclusion route, the rule create, the batch
  create and `WooPublicationService::publish()`. The repair step of 1.1 does not call it. The
  administrator reconcile route of `woo-request-scoped-access` covers the seven through the same
  method. No background job sets a grant (REQ-WRT-006).
  - **fails today**: `tests/Unit/Woo/WooAssessmentAccessTest.php`
    `testTheAssigneeIsGrantedEveryCaseRecord`, `testABatchReviewerGetsOnlyTheirBatchAndItsReviews`,
    `testTheDeliveredSetIsNeverGrantedUpdate`, `testNoGrantReachesAnotherCase`,
    `testAGrantTheCallerMayNotSetIsCountedAndNotRetried` (the sharing double throws
    `NotAuthorizedException`, built with `environmentAwareDouble()` from the real signature).
  - Through the caller: `WooReviewControllerTest::testCreateBatchGrantsTheReviewerTheirReviews`,
    `testCreateRuleGrantsTheAssignee`; `WooSourcesControllerTest::testTheAddGrantsTheCaseRecords`;
    `WooPublicationServiceTest::testTheDeliveredSetIsGrantedToTheAssignee`;
    `CreateWooDocumentReviewsTest::testTheRepairGrantsNothing`.
  - Newman folder `scoped` in the collection of 6.1: two Woo cases, a reviewer in a batch of one;
    list `wooDocumentReview` and `wooTriageRule` through the OpenRegister API as that reviewer and
    assert only the own batch's reviews and no rules are listed.
- [ ] 6.3 Every Woo route on these records checks `CaseAccessGuard` for the case (REQ-WRT-006).
  - unit: one test per controller, `testAUserWithoutAGrantOnTheCaseIsRefused`.
- [ ] 6.4 Live check after merge on the dev instance, with two Woo cases and a reviewer in a batch
  of one: list `wooDocumentReview` through the OpenRegister API and through dossiq's routes, and
  record both answers in the PR. Both must hold only that batch's reviews. Then create a batch as a
  handler who is not a coordinator over a review someone else owns, and record that the answer
  counts it in `refused` (REQ-WRT-006).

## 7. End to end

- [ ] 7.1 e2e `tests/e2e/woo-review-triage.spec.ts`: apply a rule, overturn one marking, make two
  batches, open a document page by page, try to decide with a page unseen and see the refusal,
  finish, decide. Cite REQ-WRT-001 to REQ-WRT-005.

## 8. Verify and deliver

- [ ] 8.1 `TMPDIR` set to a sibling directory beside the clone.
- [ ] 8.2 While building, run `./vendor/bin/phpunit -c phpunit-unit.xml --no-coverage --filter` on
  the touched classes and `npx vitest run` on the touched specs. The assessment service is central to
  the Woo flow, so run the full unit suite once before push.
- [ ] 8.3 Before push, once: `COMPOSER_PROCESS_TIMEOUT=0 composer check:strict`, then `npm run lint`,
  `npm run format`, `npm run check:l10n-js`, `npm run check:schema-l10n` and
  `npm run check:manifest`, plus any other leg `code-quality.yml` requires. Then hydra's
  `scripts/run-hydra-gates.sh --base origin/development`; count the gates that ran.
- [ ] 8.4 Project coverage of the added statements. When no coverage driver (xdebug or pcov) is available, take the base percentages from `development`'s last green push run, intersect its clover uncovered lines with the lines this branch adds, and say in the PR body that the number is projected, not measured.
- [ ] 8.5 One PR (or two, as above), `--base development`. Merge, never rebase. No `Co-Authored-By`.
  Done means merged on `development` with CI green. The six rows then read `yes` (build), 19.17 only once
  the live check of 6.4 holds on both paths, and `production` only with a store release.
