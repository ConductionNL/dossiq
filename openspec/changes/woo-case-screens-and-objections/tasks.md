# Tasks: woo-case-screens-and-objections

Wave 2. Row 7.13. Decisions D1 and D13. Kind: code. Build rules: `openspec/woo-build-rules.md`.

**Do not start before** `woo-requester-notices-really-go-out` and
`woo-term-is-computed-and-reported-right` are merged on `development`. If they are not, stop and say
which. If the change is too large for one PR, sections 1 to 3 are the first PR and sections 4 to 6
the second, both on this change.

Every dialog lives in its own file under `src/dialogs/` (hydra gate modal-isolation). Every
`NcSelect` carries `inputLabel` (gate nc-input-labels). A test marked **fails today** must be run on
`origin/development` first and seen red; put the failing line in the PR body.

## 1. Assess from the case page

- [ ] 1.1 The `Woo assessment` section on the CaseDetail page, listing every document with its
  assessment. NOT built: no board draws it (decision 162, UI waits for its board). Its data half
  is built generic: `GET /api/cases/{id}/document-assessments/outstanding` answers
  `{count, documents: [{id, title}]}` (`lib/Controller/DocumentAssessmentController.php`,
  `lib/Service/Assessment/OutstandingDocuments.php`; document-assessment REQ-DAS-001).
  - unit `tests/Unit/Service/Assessment/OutstandingDocumentsTest.php`,
    `tests/Unit/Controller/DocumentAssessmentControllerTest.php`.
  - The whole list the section shows is built too, generic: `GET /api/cases/{id}/document-assessments`
    answers every document under review with its title, whether it is outstanding, and the
    assessment the caller may read (`lib/Service/Assessment/AssessedDocuments.php`,
    `DocumentAssessmentController::index`; document-assessment REQ-DAS-004). Unit
    `tests/Unit/Service/Assessment/AssessedDocumentsTest.php`, the controller test above, and
    `tests/Unit/Review/OutstandingDocumentsTest.php::testUnderReviewIsEveryDocumentThatWasNotSetAside`.
    What is left of 1.1 is the section on the page (UI waits for its board, decision 162).
- [x] 1.2 The assess dialog, generic per decision 182: `src/dialogs/DocumentAssessDialog.vue`,
  opened by the header action `document-assess` (open-modal) and configured through its `props`
  for the Woo request case type. It posts `{assessments: [{documentRef, classification,
  weigeringsgronden}]}` once for the selection, and shows the server's sentence on a non-2xx. The
  grounds are read from the `wooRefusalGround` objects in OpenRegister (active and citable); dossiq
  has no grounds route (document-assessment REQ-DAS-002, REQ-WCS-001). Built to the "Wat nog
  openstaat" table of board DqWooReview.
  - vitest `tests/vitest/documentAssessDialog.spec.js`: `testItPostsOneRequestForTheSelection`,
    `testARefusalKeepsTheDocumentsOutstanding`.
  - Through the caller: the controller already exists, so the proof is the e2e in 3.2.
- [x] 1.3 The action is hidden on other case types and on an archived case (REQ-WCS-001).
  - vitest `tests/vitest/documentAssessDialog.spec.js` `testTheActionIsScopedToItsCaseType`, reading
    the real `src/manifest.json`. The PHP `WooScreensManifestTest` over every `woo-*` action is not
    written: the action is no longer named after the procedure.

## 2. Extend and decide from the case page

- [x] 2.1 Add `src/dialogs/WooExtendDialog.vue` and header action `woo-extend`, visible while the
  term runs and `countExtensions` is 0. Reason is required. Show the new deadline and the
  `noticeStatus` and `noticeReason` from the response; show a 409 sentence as is (REQ-WCS-002).
  - vitest `src/dialogs/__tests__/WooExtendDialog.spec.js`: `testAReasonIsRequired`,
    `testItShowsThatTheRequesterWasNotTold`, `testA409IsShownAsTheServerSaysIt`.
  - **fails today**: `WooScreensManifestTest::testTheExtendActionIsHiddenAfterOneExtension`.
  - Built: generic per decision 182, not as written. No `WooExtendDialog` and no `woo-extend` action. The
    Extend term act of `CaseLifecycleMenuDialog` (board DqVolgendeStap) got a case type limit
    (`caseType.maxExtensions`, REQ-TEL-001) and says whether the requester was told (REQ-TEL-002). The
    Woo case type states `maxExtensions` 1. Tests: `CaseLifecycleServiceTest`,
    `CaseTypeReaderExtensionLimitTest`, `CaseLifecycleControllerTest`, `CaseBulkActionsTest`, vitest
    `caseLifecycleMenu.spec.js` and `caseLifecycleHelpers.spec.js`. Red first:
    dq-l10-logs/r5/red-extension-limit.log.
- [x] 2.2 The decision dialog, generic per decision 182: `src/dialogs/AssessmentDecisionDialog.vue`,
  opened by the header action `assessment-decide` and configured through its `props` for the Woo
  request case type, offered while the case has no `wooPublicationStatus`. It counts the
  assessments per classification, names the outstanding documents and offers nothing to save while
  one waits, and posts `{decision: {...}}` (document-assessment REQ-DAS-003, REQ-WCS-003).
  - vitest `tests/vitest/assessmentDecisionDialog.spec.js`: `testOutstandingDocumentsBlockSubmit`,
    `testItPostsTheDecision`.

## 3. The whole path on screen

- [x] 3.1 `woo-publish` becomes visible after a decision made through the dialog: the decision
  route writes `wooPublicationStatus: ready`, the dialog emits the page refresh, and Publish is
  offered on `ready` (REQ-WCS-003).
  - vitest `tests/vitest/assessmentDecisionDialog.spec.js`: "hands over to Publish through the
    field the decision route writes" and the refresh assertion in `testItPostsTheDecision`. That
    the page really re-reads the case on the refresh is part of the e2e in 3.2 (live pass,
    decision 139).
- [ ] 3.2 e2e `tests/e2e/woo-case-screens.spec.ts`: from a Woo case with gathered documents, assess
  all, extend once, decide, publish, all through the page. Assert no request to an endpoint the page
  did not offer. Cite REQ-WCS-001, REQ-WCS-002, REQ-WCS-003. **fails today** at the first step,
  because the `Assess documents` action does not exist. (live pass, decision 139)

## 4. Register an objection

- [x] 4.1 Extract the body of `BezwaarObjectionController::open()` into a service method (for example
  `ObjectionService::open(string $bezwaarCaseId, string $contestedDecision, array $objection): array`)
  and make the controller call it. Behaviour of the existing route stays identical (REQ-WCS-004).
  - unit `tests/Unit/Controller/BezwaarObjectionControllerTest.php`: existing tests stay green;
    add `testTheRouteStillOpensThroughTheService`.
  - Built: no change needed. `BezwaarObjectionController::open()` already delegates to `BezwaarCreationHook::onBezwaarCreated()`, the service the generic lodging also calls (`lib/Controller/BezwaarObjectionController.php` line 122, covered by `tests/Unit/Service/Bezwaar/BezwaarObjectionIsReachableTest.php`). The test file this task names was not added.
- [x] 4.2 Add `WooObjectionController::open(string $id)` on POST `/api/cases/{id}/woo/objection`
  with `#[NoAdminRequired]` and a `requireCaseMutationAccess()` guard in the body. It checks the
  decision, opens the Bezwaar case with `relatedCases`, calls the service of 4.1, deletes the case
  again when that fails, and answers `{bezwaarCaseId, objectionId, deadline, isTimely}`
  (REQ-WCS-004, REQ-WCS-005).
  - **fails today** (route absent): `tests/Unit/Controller/WooObjectionControllerTest.php`
    `testAnObjectionOpensItsOwnCase`, `testNoDecisionAnswers409AndCreatesNothing`,
    `testAFailedObjectionRecordLeavesNoCase`, `testATimelyObjection`,
    `testALateObjectionIsRegisteredAndMarked`, `testAUserWithoutMutationAccessIsRefused`.
  - Gates no-admin-idor, route-auth and route-reachability must pass on this controller.
  - Built: generic per decision 182, not as written. `RemedyController::lodge()` on POST `/api/cases/{id}/remedy` over `Service/Remedy/RemedyLodging` (REQ-RML-001, REQ-RML-002). There is no `WooObjectionController`. Tests: `RemedyControllerTest`, `RemedyLodgingTest`.
- [x] 4.3 The lodge dialog, generic per decision 182: `src/dialogs/RemedyLodgeDialog.vue`, opened by
  the header action `remedy-lodge` and configured through its `props` for the Woo request case type
  (title Register an objection, the four channels, the page of the new case). On success it says
  whether the lodging was on time and opens the new case's page (`CaseDetail`: the remedy is a
  case; the two cases are linked through the case relation RemedyLodging writes). The action shows
  on every open Woo request case: the case carries no field that says it has a decision, so the
  route's 409 sentence is shown in the dialog (remedy-lodging REQ-RML-003, REQ-WCS-004).
  - vitest `tests/vitest/remedyLodgeDialog.spec.js` `testItNavigatesToTheNewBezwaarCase`.

## 5. The Bezwaar case type and the list

- [x] 5.1 Move the Bezwaar case type out of `bezwaar_seed_data.json` `_caseTypes_disabled` into a new
  fragment `lib/Settings/register.d/84-woo-bezwaar-case-type.json`, seeded with the Woo case type.
  Leave Beroep and Subsidie parked. Keep `SetupWizardStepsTest` green (REQ-WCS-006).
  - **fails today**: `tests/Unit/Settings/WooBezwaarCaseTypeSeedTest.php`
    `testTheBezwaarCaseTypeIsSeededWithTheWooCaseType` and
    `testBeroepAndSubsidieStayParked`, reading the merged register the importer reads.
  - Check the case type's term: P6W, extension P6W, Awt roll on. Test:
    `testTheBezwaarTermIsSixWeeksRolled`.
  - Built: `register.d/84-woo-bezwaar-case-type.json`, `WooBezwaarCaseTypeSeedTest`. The parked entry stays in `_caseTypes_disabled` under the same uuid, because it carries the workflow template `WorkflowGuardConformanceTest` sweeps.
- [x] 5.2 No `Bezwaren` page is added. The page of that name was deleted on 2026-09-02 because it
  was an index over `case` narrowed by case type, which the Cases page already is through its case
  type folders (`src/menu-layout.json` removalsCoverageNote). With the Bezwaar case type seeded
  (5.1) its cases are real rows there. REQ-WCS-006 is amended to say so.
  - vitest `tests/vitest/remedyLodgeDialog.spec.js`, "where a lodged objection is listed".
  - e2e in 6.1 asserts the new objection is listed on Cases under Bezwaar.

## 6. End to end and live

- [ ] 6.1 e2e `tests/e2e/woo-objection.spec.ts`: on a decided Woo case, register an objection,
  land on the Bezwaar case, follow the link back, and find the objection on the Cases page under Bezwaar. Cite
  REQ-WCS-004 and REQ-WCS-006.
- [ ] 6.2 Live check after merge on the dev instance: one Woo case taken from intake to publication
  through the page only, and one objection against it. Record the case ids in the PR or issue.

## 7. Verify and deliver

- [ ] 7.1 `TMPDIR` set to a sibling directory beside the clone.
- [ ] 7.2 While building, run `./vendor/bin/phpunit -c phpunit-unit.xml --no-coverage --filter` on
  the touched classes and `npx vitest run` on the touched specs. Judge PHPUnit by the `Tests:` line.
- [ ] 7.3 Before push, once: `COMPOSER_PROCESS_TIMEOUT=0 composer check:strict`, then `npm run lint`,
  `npm run format`, `npm run check:l10n-js`, `npm run check:schema-l10n` and
  `npm run check:manifest`, plus any other leg `code-quality.yml` requires. Every new user-facing
  string goes through the l10n catalogue. Then hydra's
  `scripts/run-hydra-gates.sh --base origin/development`; count the gates that ran.
- [ ] 7.4 Project coverage of the added statements. When no coverage driver (xdebug or pcov) is available, take the base percentages from `development`'s last green push run, intersect its clover uncovered lines with the lines this branch adds, and say in the PR body that the number is projected, not measured.
- [ ] 7.5 One PR (or two, as above), `--base development`. Merge, never rebase. No `Co-Authored-By`.
  Done means merged on `development` with CI green. Row 7.13 then reads `yes` (build), and
  `production` only with a store release.
