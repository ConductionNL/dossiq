# Tasks: woo-request-takes-over-from-opencatalogi

Wave 2. Supporting: keeps 7.1 to 7.6 and 10.8 yes (statutory: Woo art. 4.4, Awb 4:15, Awt art. 1).
Decisions D1 and D12. Kind: code. Build rules: `openspec/woo-build-rules.md`.

**Do not start before** `woo-requester-notices-really-go-out` and
`woo-term-is-computed-and-reported-right` are merged on `development`. Check with
`git log origin/development --oneline -- openspec/changes/<name>` or the archive. If either is not
merged, stop and say which.

A test marked **fails today** must be run on `origin/development` first and seen red. Put the
failing line in the PR body. Dates come from a fixed clock and a fixed calendar, never from
`today`. dossiq has no `environmentAwareDouble` helper: build every OpenRegister double from the
real class signatures (read `ObjectService`, `ObjectEntity` and the term engine classes in
openregister on `development` first; magic `Entity` getters and non-nullable returns that throw
are where local stubs lie).

## 1. Receive opencatalogi's shape

- [x] 1.1 Add `WooRequestIntake::receive(array $answers, string $receivedAt = '', string $origin = 'portal-form'): array`
  with the mapping of REQ-WTO-001. Add `portal-form` and `opencatalogi` to `ORIGINS`, to
  `INTAKE_CHANNEL` and to the `wooRequest.origin` enum in `register.d/81-woo-verzoek.json`. Make
  `subjectRef` optional in `WooRequestForm::normalise()` for those two origins only. Keep `start()`
  unchanged for `portal` and `pipelinq` (REQ-WTO-001).
  - **fails today**: `tests/Unit/Woo/WooRequestIntakeReceiveTest.php`
    `testOpencatalogisAnswerKeysBecomeAWooCase`, `testARequestForNothingIsRefusedAndWritesNothing`,
    `testEveryOutcomeCarriesAllSixKeysAsStrings`, `testThePortalDossierOriginStillNeedsASubject`.
  - Validate the written case against the real schema with `tests/Support/RealSchemaValidator`:
    `testTheReceivedCaseValidatesAgainstTheCaseSchema`.
  - Built (10 Oct): `lib/Woo/WooRequestIntake.php` `receive()`, the answer mapping in
    `lib/Woo/WooReceivedAnswers.php` (test `tests/Unit/Woo/WooReceivedAnswersTest.php`). Decision 179:
    the submit creates the case directly, no intake object in between. Phone and address go on
    `wooRequest.verzoekerTelefoon` / `verzoekerAdres` (spec amended: no requester role type is seeded).
- [x] 1.2 Add `PortalContributionProvider::receiveWooRequest(array $answers, string $receivedAt = ''): array`
  calling `receive()` with origin `portal-form`. Its constructor takes `WooRequestIntake` as an
  optional dependency, as opencatalogi's does, and answers `unavailable` with every key when it is
  null (REQ-WTO-001).
  - **fails today**: `tests/Unit/Portal/PortalContributionProviderTest.php`
    `testReceiveWooRequestHasOpencatalogisSignature`. Assert by reflection: method name, two
    parameters `array $answers` and `string $receivedAt = ''`, return type `array`. Write the
    expected signature in the test as a literal copied from opencatalogi's class at 35999c29 and
    cite the file and line.
  - Through the caller: construct the provider through the real DI container the way portaliq's
    `PortalProviderLocator::locate('dossiq')` does (`OCA\Dossiq\Portal\PortalContributionProvider`),
    and call `receiveWooRequest()` on it: `testTheProviderPortaliqLocatesReceivesARequest`.
  - Built (10 Oct): `tests/Unit/Portal/PortalContributionProviderTest.php`
    `testReceiveWooRequestHasOpencatalogisSignature`, `testWithoutTheIntakeTheAnswerIsUnavailableWithEveryKey`,
    `testTheProviderHandsTheRequestToTheIntakeAsAPortalForm`. The real-container construction is not a unit
    test (no container in phpunit-unit); it is part of the 6.1 e2e (live pass, decision 139).

## 2. armed means a term runs

- [x] 2.1 `receive()` starts the case on `receivedAt`, then reads back the case and its term
  instance and answers `armed` only per REQ-WTO-002. On a written case without a running term it
  answers `not-armed` and writes an internal timeline entry. Without OpenRegister, the register,
  the case type or the term engine it answers `unavailable` and writes nothing (REQ-WTO-002).
  - **fails today**: `tests/Unit/Woo/WooRequestIntakeReceiveTest.php`
    `testTheTermCountsFromWhenTheRequesterSentIt` (sent 2026-11-27, delivered 2026-11-30, expects
    `dueAt` 2026-12-28), `testARefusedTimerIsNotArmed`, `testNoTermEngineWritesNoCase`.
  - Built (10 Oct): `lib/Woo/WooReceivedTerm.php` (test `tests/Unit/Woo/WooReceivedTermTest.php`) and the
    three tests above. The P28D count and Awt roll themselves are the term engine's, bound by
    `DeadlineCaseCreatedListener` from the case's `receivedAt`; the intake test plays that part.
    Row 10.8 done (10 Oct, below).
  - Row 10.8: `tests/Unit/Service/AcknowledgementDutyTest.php`
    `testTheAcknowledgementNamesTheStartAndTheDueDate`, on the rendered template text of a case
    written by `receive()`. It may pass already if `intake-says-when-the-term-starts` finished it;
    say which in the PR body.
  - Row 10.8 result (10 Oct): it did NOT pass. Red first: `acknowledgement_no_address`, because a case
    written by `receive()` keeps the requester's address only in `wooRequest.verzoekerEmail` and
    `CaseContactDirectory` reads `email`/`initiator`/`betrokkenen`/`contacts`, none declared on the case
    schema. Fixed generically (decision 182): the `acknowledgement` declaration takes `addressFields`
    (`CaseTypeAcknowledgement::addressesOn()`, tried first by `AcknowledgementService`), and the Woo case
    type declares `wooRequest.verzoekerEmail` (`specs/burger-notifications/spec.md` REQ-ACK-ADDR-001).
    Green: `testTheAcknowledgementNamesTheStartAndTheDueDate` and
    `CaseTypeAcknowledgementTest::testTheAddressIsReadWhereTheCaseTypeDeclaresIt`.

## 3. Parity on opencatalogi's fixtures

- [x] 3.1 Add `tests/Unit/Service/Term/StatutoryTermParityTest.php` (named generically, decision 182; it was
  `tests/Unit/Woo/WooTermParityTest.php`). One test per scenario of REQ-WTO-003, each with
  a docblock line `Mirrors opencatalogi tests/Unit/Service/Woo/<File>::<test>`. Mirror at least
  `StatutoryTermTest::testTheFirstExtensionIsGrantedForTwoWeeks`, `testTheSecondExtensionIsRefused`,
  `testARefusedSecondExtensionChangesNothing`, `testAPauseSuspendsTheTermAndLeavesNoDueMoment`,
  `testAResumePutsTheTermBackOnTheClock`, `testResumingARunningTermIsRefused`, and
  `WooRequestServiceTest::testTermsReportCountsEveryOutcome` and
  `testAShareOfNothingIsNotFullCompliance`. Drive them through dossiq's real call sites:
  `WOOAssessmentController::extendDeadline()`, `InformationRequestService`, and
  `DeadlineReportingController` (REQ-WTO-003).
  - **fails today**: `testAShareOfNothingIsNotFullCompliance`, unless
    `woo-term-is-computed-and-reported-right` already answers null. If it does not, fix the share
    in `DeadlineReportingService` here and say so in the PR body.
  - The others may pass on arrival if wave 1 did its work. That is the point of a parity suite.
    State in the PR body which ones were red before and which were green.
  - Done: the six term scenarios were green on arrival; `testTermsReportCountsEveryOutcome` and
    `testAShareOfNothingIsNotFullCompliance` were red (no `met`/`missed`/`running`/`suspended`, and
    the share counted running terms in its denominator and answered 0.0 for nothing decided). Fixed
    generically: `lib/Service/Termijn/TermOutcome.php` (own test `tests/Unit/Service/Termijn/TermOutcomeTest.php`)
    classifies every term and computes `metShare` = met / (met + missed), null when nothing is
    decided; `DeadlineReportingService` reports it per case type and on the dashboard snapshot, and
    `src/views/termijn/tdWidgetMixin.js` shows a dash for null.

## 4. The import

- [x] 4.1 Declare `formerReferences` (array of `{application, reference}`) on the case in
  `lib/Settings/dossiq_register.json`, add it to the case search declaration in
  `register.d/39-search-declarations.json`, and bump the register version (REQ-WTO-004).
  - unit: `tests/Unit/Settings/CaseSchemaTest.php` `testFormerReferencesIsDeclaredAndSearchable`.
  - Built (10 Oct): register 0.20.27, `matchType: exact` like `identifier` (a whole reference, not half).
- [x] 4.2 The import, generic per decision 182 (amended 10 Oct): no `OpenCatalogiWooImport`. The
  capability is `case-record-import` (`specs/case-record-import/spec.md`, REQ-CRI-001 to 003) and the
  Woo part is the `recordImports` declaration on the Woo case type (REQ-WTO-004).
  - Built: `lib/Service/Import/CaseRecordImport.php` (`run()`, `dryRun()`), the declaration reader
    `lib/Service/Import/RecordCaseMapping.php`, the OpenRegister side `lib/Service/Import/RecordImportStore.php`,
    and the term carry `lib/Service/Term/TermCarryOver.php` (generic term-engine behaviour: cancel the fresh
    timer, write the source's end, extensions and status, re-arm through `armBeslistermijn` with the breach
    mark, suspend at once, record each extension). `caseType.recordImports` declared in
    `lib/Settings/dossiq_register.json` (caseType 1.17.0, register 0.20.28); the opencatalogi declaration on
    the Woo case type in `register.d/81-woo-verzoek.json` (object 1.2.0).
  - Tests: `tests/Unit/Service/Import/CaseRecordImportTest.php` runs the Woo case type's real declaration over
    `tests/Fixtures/opencatalogi-woo-requests.json`: `testARunningRequestMovesWithItsRemainingTime`,
    `testAnExtendedSuspendedRequestKeepsBoth`, `testEveryStatusMapsToItsStage`,
    `testASecondRunImportsNothingAndArmsNothing`, `testAHalfFinishedRequestIsCompletedNotDuplicated`,
    `testARefusedStampIsAFailureNotAMigration`, `testOpencatalogisTimerIsNeverTouched`,
    `testWithoutOpencatalogiNothingIsImported`, `testEveryWrittenCaseFitsTheCaseSchema` (real schema). Plus
    `RecordCaseMappingTest`, `RecordImportStoreTest`, `tests/Unit/Service/Term/TermCarryOverTest.php`.
  - `lib/Woo/OpenCatalogiWooCase.php` (#3608) is now unused by the import: the refactor programme removes it.
- [x] 4.3 The command, generic: `lib/Command/ImportCaseRecordsCommand.php`
  (`dossiq:case:import-records <caseType> [--import=<key>] [--dry-run]`), registered in `appinfo/info.xml`.
  - Through the caller: `tests/Unit/Command/ImportCaseRecordsCommandTest.php`
    `testTheCommandPrintsTheUnmigratedCount`, built on the real import class with an in-memory register.
- [ ] 4.4 Search: a case is found by a former reference (REQ-WTO-004).
  - e2e `tests/e2e/woo-takeover.spec.ts`: seed one imported case and search "WOO-2026-A1B2C3" on
    the cases list. Cite REQ-WTO-004.
  - written (11 Oct): `tests/e2e/woo-takeover.spec.ts` seeds an opencatalogi `wooRequest`, runs
    `occ dossiq:case:import-records woo-verzoek`, reads the case (status, startDate, deadline,
    formerReferences, the source's `migratedTo`), finds it with `_search` on the old number, and runs
    the import again expecting 0 imported. It skips on a shared instance (the import reads every
    wooRequest) and when opencatalogi is absent. The run is owed to the live pass (decision 139).

## 5. Drafting through filinq

- [x] 5.1 filinq's contract read on filinq `development` (10 Oct 2026): `OCA\Filinq\Event\DocumentGenerationRequestedEvent`
  (`lib/Event/DocumentGenerationRequestedEvent.php`) with `templateSlug` + `templateNamespace`, `data`,
  `object`, `format`, handled by `DocumentGenerationRequestedListener` through
  `DocumentGenerationRequestService::generate()`. The filinq side under decision 156 is therefore
  built; only the `woo-besluit` / `woo-inventarislijst` template content is filinq's
  `woo-request-workflow` (not on development).
- [x] 5.2 Decision 182: generic, not `WooDecisionDrafts`. `lib/Service/Drafts/CaseDocumentDrafts.php`
  (`draft(caseId, purpose)`) over the case type's `documentDrafts` (`register.d/89-case-document-drafts.json`),
  called from `WOODecisionService::assembleDecision()` for `decision` and `inventory` (answers under
  `draft` and `inventoryDraft`). REQ-CDD-001/003, REQ-WTO-005/006.
  - done: `tests/Unit/Service/Drafts/CaseDocumentDraftsTest.php` (7: the Woo case type's real declaration
    drafted and filed, filinq's error is the reason, unhandled is filinq-missing, undeclared asks nothing,
    unbuildable data refused before filinq, data beside the case without a data key, inventory);
    through the caller `WOODecisionServiceTest::testTheDecisionCarriesTheDraftStatus`.
- [x] 5.3 The `wooDecision` shape is configuration on the Woo case type, built by the generic
  `lib/Service/Drafts/CaseDraftData.php` (REQ-CDD-002).
  - done: `tests/Unit/Service/Drafts/CaseDraftDataTest.php` (6); the shape over one assessment of each
    classification in `CaseDocumentDraftsTest::testTheWooDecisionIsDraftedFromItsCaseTypesTemplateAndFiled`.

## 6. End to end and live

- [ ] 6.1 `tests/e2e/woo-takeover.spec.ts` also: call the portal form path through portaliq's
  delivery job on the dev instance (or the provider method through a test route if portaliq is not
  installed in CI) and read the case back with its `deadline`. Cite REQ-WTO-001 and REQ-WTO-002.
  - blocked (11 Oct): portaliq's `PortalWooRequestDelivery` (development) delivers a Woo form only to
    opencatalogi (`APP = 'opencatalogi'`), so no portal form reaches dossiq's `receiveWooRequest()`
    yet, and dossiq ships no test route by design. Switching the delivery target is the decision-179
    form-to-destination change the architect owns. Until then the provider method is covered by
    `tests/Unit/Portal/PortalContributionProviderTest.php` and `tests/Unit/Woo/WooRequestIntakeReceiveTest.php`.
- [ ] 6.2 Live check after merge on the dev instance: create two opencatalogi `wooRequest` objects
  (one running, one suspended), run `occ dossiq:case:import-records woo-verzoek`, and record the output,
  the two cases' `deadline`, the two FlowTimers' fire moments and the sources' `migratedTo`. Run it
  a second time and record that nothing changed.

## 7. Verify and deliver

- [ ] 7.1 `TMPDIR` set to a sibling directory beside the clone, never inside it.
- [ ] 7.2 While building, run `./vendor/bin/phpunit -c phpunit-unit.xml --no-coverage --filter`
  on the touched classes and judge by the `Tests:` line. The term code is central, so run the full
  unit suite once before push.
- [ ] 7.3 Before push, once: `COMPOSER_PROCESS_TIMEOUT=0 composer check:strict`, then `npm run lint`,
  `npm run format`, `npm run check:l10n-js`, `npm run check:schema-l10n` and
  `npm run check:manifest`, plus any other leg `code-quality.yml` requires (read `package.json`).
  Then hydra's `scripts/run-hydra-gates.sh --base origin/development`, and count the gates that ran.
- [ ] 7.4 Project coverage of the added statements. When no coverage driver (xdebug or pcov) is available, take the base percentages from `development`'s last green push run, intersect its clover uncovered lines with the lines this branch adds, and say in the PR body that the number is projected, not measured.
- [ ] 7.5 One PR, `--base development`. Merge development in, never rebase. No `Co-Authored-By` on
  any commit. Done means merged on `development` with CI green. The supported rows stay yes; they
  read `production` for dossiq only with a store release. In the PR body, name the two wave 3
  changes that now may start: `opencatalogi/woo-request-intake-hands-over-to-dossiq` and
  `portaliq/woo-intake-delivers-to-dossiq`.
