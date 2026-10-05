# Tasks: woo-request-takes-over-from-opencatalogi

Wave 2. Supporting: keeps 7.1 to 7.6 and 10.8 yes (statutory: Woo art. 4.4, Awb 4:15, Awt art. 1).
Decisions D1 and D12. Kind: code. Build rules: `~/memcap-work/woo-build/LANE-RULES-BUILD.md`.

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

- [ ] 1.1 Add `WooRequestIntake::receive(array $answers, string $receivedAt = '', string $origin = 'portal-form'): array`
  with the mapping of REQ-WTO-001. Add `portal-form` and `opencatalogi` to `ORIGINS`, to
  `INTAKE_CHANNEL` and to the `wooRequest.origin` enum in `register.d/81-woo-verzoek.json`. Make
  `subjectRef` optional in `WooRequestForm::normalise()` for those two origins only. Keep `start()`
  unchanged for `portal` and `pipelinq` (REQ-WTO-001).
  - **fails today**: `tests/Unit/Woo/WooRequestIntakeReceiveTest.php`
    `testOpencatalogisAnswerKeysBecomeAWooCase`, `testARequestForNothingIsRefusedAndWritesNothing`,
    `testEveryOutcomeCarriesAllSixKeysAsStrings`, `testThePortalDossierOriginStillNeedsASubject`.
  - Validate the written case against the real schema with `tests/Support/RealSchemaValidator`:
    `testTheReceivedCaseValidatesAgainstTheCaseSchema`.
- [ ] 1.2 Add `PortalContributionProvider::receiveWooRequest(array $answers, string $receivedAt = ''): array`
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

## 2. armed means a term runs

- [ ] 2.1 `receive()` starts the case on `receivedAt`, then reads back the case and its term
  instance and answers `armed` only per REQ-WTO-002. On a written case without a running term it
  answers `not-armed` and writes an internal timeline entry. Without OpenRegister, the register,
  the case type or the term engine it answers `unavailable` and writes nothing (REQ-WTO-002).
  - **fails today**: `tests/Unit/Woo/WooRequestIntakeReceiveTest.php`
    `testTheTermCountsFromWhenTheRequesterSentIt` (sent 2026-11-27, delivered 2026-11-30, expects
    `dueAt` 2026-12-28), `testARefusedTimerIsNotArmed`, `testNoTermEngineWritesNoCase`.
  - Row 10.8: `tests/Unit/Service/AcknowledgementDutyTest.php`
    `testTheAcknowledgementNamesTheStartAndTheDueDate`, on the rendered template text of a case
    written by `receive()`. It may pass already if `intake-says-when-the-term-starts` finished it;
    say which in the PR body.

## 3. Parity on opencatalogi's fixtures

- [ ] 3.1 Add `tests/Unit/Woo/WooTermParityTest.php`. One test per scenario of REQ-WTO-003, each with
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

## 4. The import

- [ ] 4.1 Declare `formerReferences` (array of `{application, reference}`) on the case in
  `lib/Settings/dossiq_register.json`, add it to the case search declaration in
  `register.d/39-search-declarations.json`, and bump the register version (REQ-WTO-004).
  - unit: `tests/Unit/Settings/CaseSchemaTest.php` `testFormerReferencesIsDeclaredAndSearchable`.
- [ ] 4.2 Add `lib/Woo/OpenCatalogiWooImport.php` with `run(bool $dryRun = false): array` per
  REQ-WTO-004. Resolve opencatalogi's register and `wooRequest` schema by slug through
  OpenRegister, as the system, `_rbac: false`. Find an existing case by
  `wooRequest.originReference` before creating. Arm the timer through the same arming code
  `ArmTermijnEngineTimers` uses (REQ-TOT-006), with SLA `days(startDate to deadline)`, and suspend
  at once for a suspended source. Stamp `migratedTo` and `migratedAt` last (REQ-WTO-004).
  - **fails today** (the class does not exist): `tests/Unit/Woo/OpenCatalogiWooImportTest.php`
    `testARunningRequestMovesWithItsRemainingTime`, `testAnExtendedSuspendedRequestKeepsBoth`,
    `testEveryStatusMapsToItsStage` (all five), `testASecondRunImportsNothingAndArmsNothing`,
    `testAHalfFinishedRequestIsCompletedNotDuplicated`,
    `testARefusedStampIsAFailureNotAMigration`, `testOpencatalogisTimerIsNeverTouched`,
    `testWithoutOpencatalogiNothingIsImported`.
  - Use opencatalogi's real `wooRequest` shape as the fixture: copy one object of each status
    from `lib/Settings/opencatalogi_mock_register.json` at 35999c29 into
    `tests/Fixtures/opencatalogi-woo-requests.json` and cite the source line.
- [ ] 4.3 Add `lib/Command/ImportOpenCatalogiWooRequests.php` (`dossiq:woo:import-opencatalogi`,
  `--dry-run`) and register it in `appinfo/info.xml` (REQ-WTO-004).
  - Through the caller: `tests/Unit/Command/ImportOpenCatalogiWooRequestsTest.php`
    `testTheCommandPrintsTheUnmigratedCount`, built on the real import class with a register
    double.
- [ ] 4.4 Search: a case is found by a former reference (REQ-WTO-004).
  - e2e `tests/e2e/woo-takeover.spec.ts`: seed one imported case and search "WOO-2026-A1B2C3" on
    the cases list. Cite REQ-WTO-004.

## 5. Drafting through filinq

- [ ] 5.1 Read filinq's `woo-request-workflow` change on filinq `development` at the moment you
  build. It names the template service dossiq calls. Write that name, its arguments and its
  return keys in this change's `design.md` (create it) before writing the adapter. If filinq has
  not merged that service, stop this task, finish the rest, and say so in the PR body (REQ-WTO-005).
- [ ] 5.2 Add `lib/Woo/WooDecisionDrafts.php` with `draft(string $caseId): array` per REQ-WTO-005 and
  REQ-WTO-006. Resolve filinq through `FleetAppId::getService()`. Call it from
  `WOODecisionService::assembleDecision()` and return its answer under `draft` (REQ-WTO-005,
  REQ-WTO-006).
  - **fails today**: `tests/Unit/Service/WOODecisionServiceTest.php`
    `testTheDecisionCarriesTheDraftStatus`, `testWithoutFilinqNoPlaceholderIsFiled`.
  - Through the caller: `tests/Unit/Controller/WOOAssessmentControllerTest.php`
    `testCreateDecisionAnswersTheDraftStatus`.
  - Contract: `tests/Unit/Woo/WooDecisionDraftsContractTest.php` asserts the filinq method name,
    arguments and return keys written in `design.md` as literals. filinq's own change must test
    its side with the same literals; name that test in the PR body.

## 6. End to end and live

- [ ] 6.1 `tests/e2e/woo-takeover.spec.ts` also: call the portal form path through portaliq's
  delivery job on the dev instance (or the provider method through a test route if portaliq is not
  installed in CI) and read the case back with its `deadline`. Cite REQ-WTO-001 and REQ-WTO-002.
- [ ] 6.2 Live check after merge on the dev instance: create two opencatalogi `wooRequest` objects
  (one running, one suspended), run `occ dossiq:woo:import-opencatalogi`, and record the output,
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
- [ ] 7.4 Project coverage of the added statements as LANE-RULES-BUILD says, and say it is projected.
- [ ] 7.5 One PR, `--base development`. Merge development in, never rebase. No `Co-Authored-By` on
  any commit. Done means merged on `development` with CI green. The supported rows stay yes; they
  read `production` for dossiq only with a store release. In the PR body, name the two wave 3
  changes that now may start: `opencatalogi/woo-request-intake-hands-over-to-dossiq` and
  `portaliq/woo-intake-delivers-to-dossiq`.
