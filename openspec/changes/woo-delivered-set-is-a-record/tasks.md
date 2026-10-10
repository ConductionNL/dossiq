# Tasks: woo-delivered-set-is-a-record

Wave 2. Rows 19.15 and 19.16. Decision D1. Kind: code. Build rules: `openspec/woo-build-rules.md`.

Before starting, read on `development` at that moment: openregister's `object-archive-state`
change (is REQ-OAS-004 extended to file writes, merged or not) and filinq's
`anonymization-review-workbench` (does the viewer component exist, and what is it called). Write
both answers in the PR body. A test marked **fails today** must be run on `origin/development`
first and seen red.

## 1. The set and its manifest

- [x] 1.1 (`lib/Settings/register.d/86-woo-delivered-set.json`, 85 was taken; no authorization block, read like the case and its assessments; register 0.20.24; `WooDeliveredSetWriterTest` validates a written set against it) Add schema `wooDeliveredSet` in `lib/Settings/register.d/85-woo-delivered-set.json` with the
  properties of REQ-WDS-001, authorization following the case (read with case read access, no
  public read), and bump the register version (REQ-WDS-001).
  - unit `tests/Unit/Settings/WooDeliveredSetSchemaTest.php` `testTheSetIsNotPubliclyReadable`.
- [x] 1.2 (hashes over the bytes `WooCaseDocuments` reads through `IRootFolder`; `deliver()` wraps open, send, freeze or discard; `tests/Unit/Woo/WooDeliveredSetWriterTest.php`) Add `lib/Woo/WooDeliveredSetWriter.php`: `open(caseId, decisionId, disclosable): array`
  writes the pending set with hashes read from the real file bytes through `IRootFolder`;
  `freeze(setId, publicationId): void`; `discard(setId): void`. The set hash follows the rule in
  REQ-WDS-001 exactly (REQ-WDS-001).
  - unit `tests/Unit/Woo/WooDeliveredSetWriterTest.php`: `testTheSetHashFollowsTheRule` (a fixed
    three-item fixture with a hand-computed expected hash in the test),
    `testTheDeelsOpenbaarItemCarriesTheRedactedBytesHash`.
- [x] 1.3 (through `WooCaseLedger::deliver()`; a set that cannot be written refuses the publish with `delivered_set_not_written`, 503; tests in `tests/Unit/Service/WooPublicationDeliveredSetTest.php` and `WOOAssessmentControllerTest::testPublishDecisionAnswersTheSetId`) Call it from `WooPublicationService::publish()`: open before the publication is created,
  freeze after, discard on failure (REQ-WDS-001).
  - **fails today**: `tests/Unit/Service/WooPublicationServiceTest.php`
    `testAPublishWritesAFrozenSet`, `testAFailedPublishLeavesNoSet`,
    `testThePayloadCarriesNoOriginalRef`.
  - Through the caller: `tests/Unit/Controller/WOOAssessmentControllerTest.php`
    `testPublishDecisionAnswersTheSetId`.
  - Validate the written set against the real schema with `tests/Support/RealSchemaValidator`:
    `testTheWrittenSetValidatesAgainstItsSchema`.

## 2. Fixed contents

- [x] 2.1 (`lib/Listener/WooDeliveredSetGuard.php`, registered in `ImmutabilityListenerRegistrar` beside the other pre-persist guards; `tests/Unit/Listener/WooDeliveredSetGuardTest.php` on the real event classes, `tests/Unit/AppInfo/ImmutabilityListenerRegistrarTest.php`) Add a listener on OpenRegister's `ObjectUpdatingEvent` and `ObjectDeletingEvent` (check the
  real class names and their `getObject()` / `getNewObject()` accessors in openregister on
  `development`; do not fake the event) that refuses writes to a frozen set and to the assessments
  it names. Register it in `Application.php` (REQ-WDS-002).
  - **fails today**: `tests/Unit/Listener/WooDeliveredSetGuardTest.php`
    `testADeliveredVerdictCannotBeChanged`, `testAFrozenSetCannotBeDeleted`,
    `testAnAssessmentOutsideTheSetCanStillChange`. Construct the real event classes.
  - Through the caller: `tests/Unit/AppInfo/ApplicationTest.php`
    `testTheDeliveredSetGuardIsRegistered`.
- [x] 2.2 (`WooDeliveredSetWriter::keepTheDeliveredBytes()` copies the bytes that went out into the set's folder; `freeze()` sets OpenRegister's marker (state `geleverd`) through `ArchiveHandler`, and the withdraw stamp lifts it for its one write; fragment 86 declares `x-openregister-archive`; `WooDeliveredSetGuard` lets a metadata-only write (the marker) through; tests `WooDeliveredSetWriterTest::testAFrozenFileRefusesAWrite`, `::testWithoutThePlatformFreezeTheSetIsStillRecorded`, `WooDeliveredSetGuardTest::testAFreezeMarkerOnAFrozenSetIsNotADataChange`; red in dq-l10-logs/red-frozenfile.log. The file refusal itself is openregister's, built as dependency PR build/dep-dossiq-file-write-freeze (object-archive-state W.1-W.5); archive this change only after that has landed) File writes: if openregister's `object-archive-state` file-write half is merged, set its
  frozen marker on the delivered files at freeze and test that a write is refused
  (`testAFrozenFileRefusesAWrite`). If it is not merged, do not build a dossiq copy of it; say in
  the PR body that a changed file is caught by re-verification only, and leave this box open with
  that sentence (REQ-WDS-002).
- [x] 2.3 (`WooPublicationDeliveredSetTest::testASecondDeliveryIsANewSetAndAWithdrawKeepsTheSetFrozen`, `WooDeliveredSetWriterTest`) A second delivery writes a new set with `supersedes`; a withdraw stamps `withdrawnAt` and
  leaves the set frozen (REQ-WDS-002).
  - unit: `testASecondDeliveryIsANewSet`, `testAWithdrawKeepsTheSetFrozen` in
    `WooPublicationServiceTest`.

## 3. Re-verification

- [x] 3.1 (`WooDeliveredSetController`, route `wooDeliveredSet#verify`, `VerifyWooDeliveredSetCommand`, `WooDeliveredSetVerifier`; tests `WooDeliveredSetVerifierTest`, `WooDeliveredSetControllerTest` incl. the command) Add `WooDeliveredSetController::verify(string $id, string $setId)` on GET
  `/api/cases/{id}/woo/delivered-sets/{setId}/verify` with `#[NoAdminRequired]` and a case read
  guard in the body, and `occ dossiq:woo:verify-delivered-set` calling the same service
  (REQ-WDS-003).
  - **fails today**: `tests/Unit/Woo/WooDeliveredSetVerifierTest.php` `testAnUntouchedSetVerifies`,
    `testAReplacedFileIsCaught`, `testAnUnreadableFileIsMissingNotMatch`.
  - Through the caller: `tests/Unit/Controller/WooDeliveredSetControllerTest.php`
    `testVerifyRefusesAUserWithoutCaseAccess`, `testVerifyAnswersTheItemStatuses`.

## 4. Compare

- [x] 4.1 (section `case-woo-delivered-sets` on the Data tab after Woo publication, page `WooDeliveredSetDetail`; check:manifest 0. DqPubliceren does not draw it: paired design-system PR) Add a `Delivered sets` section on the Woo case page and a set detail page in
  `src/manifest.json` listing every item with both files, classification and hash (REQ-WDS-004).
  - `npm run check:manifest` exits 0.
- [x] 4.2 (`src/dialogs/WooCompareDialog.vue` over filinq's `OCA.Filinq.mountCompare(el, { original, delivered, labels })` (files `{ fileName, mimeType, url }`, returns `{ unmount() }`, emits nothing), loaded by `src/utils/filinqCompare.js` from `filinq/js/filinq-compare.js`; built in filinq as anonymization-review-workbench REQ-DDARW-014, dependency PR on ConductionNL/filinq branch build/dep-dossiq-compare-view; per-item Compare on a redacted item in `src/components/woo/WooDeliveredSetItems.vue` (registry `woo-delivered-set-items`, placed on `WooDeliveredSetDetail`); both files read from `GET /api/cases/{id}/woo/delivered-sets/{setId}/items/{index}[/{side}]` (`WooDeliveredSetController::item/file`, `lib/Woo/WooDeliveredSetFiles.php`) behind the case read guard. Tests: vitest `tests/vitest/wooCompareDialog.spec.js` (the vitest suite only collects tests/vitest), PHPUnit `WooDeliveredSetFilesTest`, `WooDeliveredSetControllerTest::testTheCompareReadsAnswerBothFiles`. The two panes open on page 1 each and scroll on their own; a synchronised page scroll is not built) Add `src/dialogs/WooCompareDialog.vue`. With filinq's viewer resolvable, it mounts the
  viewer with the original and the delivered file side by side. Without it, it says the compare
  view needs filinq and offers both files as links (REQ-WDS-004).
  - vitest `tests/vitest/wooCompareDialog.spec.js`: `testWithoutTheViewerItSaysSo`,
    `testItPassesBothFilesToTheViewer`.
  - Contract: name filinq's viewer component, its props and the event it emits in the PR body,
    as read in filinq on `development`. filinq's change must test that same contract.

## 5. End to end and live

- [ ] 5.1 (written; the run is the live pass, decision 139) e2e `tests/e2e/woo-delivered-set.spec.ts`: publish a Woo case, open its delivered set, run
  verify and see every item match, then try to change an assessment and see the refusal. Cite
  REQ-WDS-001 to REQ-WDS-003.
- [ ] 5.2 (live pass, decision 139) Live check after merge on the dev instance: publish one Woo case, read the set through the
  OpenRegister API, run `occ dossiq:woo:verify-delivered-set`, overwrite the redacted file by hand,
  and run it again. Record both outputs.

## 6. Verify and deliver

- [ ] 6.1 `TMPDIR` set to a sibling directory beside the clone.
- [ ] 6.2 While building, run `./vendor/bin/phpunit -c phpunit-unit.xml --no-coverage --filter` on
  the touched classes and `npx vitest run` on the touched specs. Judge PHPUnit by the `Tests:` line.
- [ ] 6.3 Before push, once: `COMPOSER_PROCESS_TIMEOUT=0 composer check:strict`, then `npm run lint`,
  `npm run format`, `npm run check:l10n-js`, `npm run check:schema-l10n` and
  `npm run check:manifest`, plus any other leg `code-quality.yml` requires. Then hydra's
  `scripts/run-hydra-gates.sh --base origin/development`; count the gates that ran.
- [ ] 6.4 Project coverage of the added statements. When no coverage driver (xdebug or pcov) is available, take the base percentages from `development`'s last green push run, intersect its clover uncovered lines with the lines this branch adds, and say in the PR body that the number is projected, not measured.
- [ ] 6.5 One PR, `--base development`. Merge, never rebase. No `Co-Authored-By`. Done means merged on
  `development` with CI green. 19.15 and 19.16 then read `yes` (build); 19.16 only with filinq
  installed. `production` only with a store release.
