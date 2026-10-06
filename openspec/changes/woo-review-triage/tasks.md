# Tasks: woo-review-triage

Wave 3. Rows 19.7, 19.8, 19.9, 19.10, 19.11 and 19.17. Decision D1. Kind: code. Build rules: `openspec/woo-build-rules.md`.

**Do not start before** `woo-request-corpus-collection` is merged on `development`. Read
openregister's `rbac-inherits-to-children` on `development` and write in the PR body whether the
platform reads `x-openregister-hierarchy` yet. A test marked **fails today** must be run on
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
- [ ] 1.2 POST `/api/cases/{id}/woo/documents/{documentRef}/relevance` with `{relevance}`, case
  mutation guard. `getOutstanding()` skips out-of-scope documents. The case summary reports the
  three counts (REQ-WRT-001).
  - **fails today**: `tests/Unit/Service/WOODocumentAssessmentServiceTest.php`
    `testAnOutOfScopeDocumentNeedsNoVerdict`, `testUnmarkedDocumentsAreOutstanding`.
  - Through the caller: `tests/Unit/Controller/WooReviewControllerTest.php`
    `testTheSummaryReportsRelevanceBesideVerdicts` (the ten-document fixture).
  - `allDocumentsAssessed()` now means every in-scope document has a verdict and none is unmarked.
    Test: `testTheDecisionWaitsForUnmarkedDocuments`.

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

- [ ] 6.1 Declare `x-openregister-hierarchy` on the eight schemas of REQ-WRT-006, with their case
  property as the edge, in the form `parentCase` uses. Make sure the key survives
  `SchemaSlugMap::SCHEMA_ANNOTATION_KEYS`. Remove any authenticated-read grant on them. Until
  OpenRegister reads the hierarchy, set their `x-openregister-authorization` read and update to
  administrators only (REQ-WRT-006).
  - **fails today**: `tests/Unit/Settings/WooChildSchemasTest.php`
    `testEveryWooChildDeclaresItsCaseAsParent`, `testNoWooChildIsReadableByAllUsers`, reading the
    merged register the importer reads.
- [ ] 6.2 Every Woo route on these records checks `CaseAccessGuard` for the case (REQ-WRT-006).
  - unit: one test per controller, `testAUserWithoutAGrantOnTheCaseIsRefused`.
- [ ] 6.3 Live check after merge on the dev instance, with two Woo cases and a user granted on one:
  list `wooDocumentAssessment` through the OpenRegister API and through dossiq's routes, and record
  both results. If OpenRegister does not yet enforce the hierarchy, the API answer must be empty for
  that user; 19.17 then reads `yes` on dossiq's routes and the PR body says the API half waits on
  `rbac-inherits-to-children`.

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
  Done means merged on `development` with CI green. The six rows then read `yes` (build), with 19.17's
  API half as stated in 6.3, and `production` only with a store release.
