# Tasks: woo-delivered-set-is-a-record

Wave 2. Rows 19.15 and 19.16. Decision D1. Kind: code. Build rules: `openspec/woo-build-rules.md`.

Before starting, read on `development` at that moment: openregister's `object-archive-state`
change (is REQ-OAS-004 extended to file writes, merged or not) and filinq's
`anonymization-review-workbench` (does the viewer component exist, and what is it called). Write
both answers in the PR body. A test marked **fails today** must be run on `origin/development`
first and seen red.

## 1. The set and its manifest

- [ ] 1.1 Add schema `wooDeliveredSet` in `lib/Settings/register.d/85-woo-delivered-set.json` with the
  properties of REQ-WDS-001, authorization following the case (read with case read access, no
  public read), and bump the register version (REQ-WDS-001).
  - unit `tests/Unit/Settings/WooDeliveredSetSchemaTest.php` `testTheSetIsNotPubliclyReadable`.
- [ ] 1.2 Add `lib/Woo/WooDeliveredSetWriter.php`: `open(caseId, decisionId, disclosable): array`
  writes the pending set with hashes read from the real file bytes through `IRootFolder`;
  `freeze(setId, publicationId): void`; `discard(setId): void`. The set hash follows the rule in
  REQ-WDS-001 exactly (REQ-WDS-001).
  - unit `tests/Unit/Woo/WooDeliveredSetWriterTest.php`: `testTheSetHashFollowsTheRule` (a fixed
    three-item fixture with a hand-computed expected hash in the test),
    `testTheDeelsOpenbaarItemCarriesTheRedactedBytesHash`.
- [ ] 1.3 Call it from `WooPublicationService::publish()`: open before the publication is created,
  freeze after, discard on failure (REQ-WDS-001).
  - **fails today**: `tests/Unit/Service/WooPublicationServiceTest.php`
    `testAPublishWritesAFrozenSet`, `testAFailedPublishLeavesNoSet`,
    `testThePayloadCarriesNoOriginalRef`.
  - Through the caller: `tests/Unit/Controller/WOOAssessmentControllerTest.php`
    `testPublishDecisionAnswersTheSetId`.
  - Validate the written set against the real schema with `tests/Support/RealSchemaValidator`:
    `testTheWrittenSetValidatesAgainstItsSchema`.

## 2. Fixed contents

- [ ] 2.1 Add a listener on OpenRegister's `ObjectUpdatingEvent` and `ObjectDeletingEvent` (check the
  real class names and their `getObject()` / `getNewObject()` accessors in openregister on
  `development`; do not fake the event) that refuses writes to a frozen set and to the assessments
  it names. Register it in `Application.php` (REQ-WDS-002).
  - **fails today**: `tests/Unit/Listener/WooDeliveredSetGuardTest.php`
    `testADeliveredVerdictCannotBeChanged`, `testAFrozenSetCannotBeDeleted`,
    `testAnAssessmentOutsideTheSetCanStillChange`. Construct the real event classes.
  - Through the caller: `tests/Unit/AppInfo/ApplicationTest.php`
    `testTheDeliveredSetGuardIsRegistered`.
- [ ] 2.2 File writes: if openregister's `object-archive-state` file-write half is merged, set its
  frozen marker on the delivered files at freeze and test that a write is refused
  (`testAFrozenFileRefusesAWrite`). If it is not merged, do not build a dossiq copy of it; say in
  the PR body that a changed file is caught by re-verification only, and leave this box open with
  that sentence (REQ-WDS-002).
- [ ] 2.3 A second delivery writes a new set with `supersedes`; a withdraw stamps `withdrawnAt` and
  leaves the set frozen (REQ-WDS-002).
  - unit: `testASecondDeliveryIsANewSet`, `testAWithdrawKeepsTheSetFrozen` in
    `WooPublicationServiceTest`.

## 3. Re-verification

- [ ] 3.1 Add `WooDeliveredSetController::verify(string $id, string $setId)` on GET
  `/api/cases/{id}/woo/delivered-sets/{setId}/verify` with `#[NoAdminRequired]` and a case read
  guard in the body, and `occ dossiq:woo:verify-delivered-set` calling the same service
  (REQ-WDS-003).
  - **fails today**: `tests/Unit/Woo/WooDeliveredSetVerifierTest.php` `testAnUntouchedSetVerifies`,
    `testAReplacedFileIsCaught`, `testAnUnreadableFileIsMissingNotMatch`.
  - Through the caller: `tests/Unit/Controller/WooDeliveredSetControllerTest.php`
    `testVerifyRefusesAUserWithoutCaseAccess`, `testVerifyAnswersTheItemStatuses`.

## 4. Compare

- [ ] 4.1 Add a `Delivered sets` section on the Woo case page and a set detail page in
  `src/manifest.json` listing every item with both files, classification and hash (REQ-WDS-004).
  - `npm run check:manifest` exits 0.
- [ ] 4.2 Add `src/dialogs/WooCompareDialog.vue`. With filinq's viewer resolvable, it mounts the
  viewer with the original and the delivered file side by side. Without it, it says the compare
  view needs filinq and offers both files as links (REQ-WDS-004).
  - vitest `src/dialogs/__tests__/WooCompareDialog.spec.js`: `testWithoutTheViewerItSaysSo`,
    `testItPassesBothFilesToTheViewer`.
  - Contract: name filinq's viewer component, its props and the event it emits in the PR body,
    as read in filinq on `development`. filinq's change must test that same contract.

## 5. End to end and live

- [ ] 5.1 e2e `tests/e2e/woo-delivered-set.spec.ts`: publish a Woo case, open its delivered set, run
  verify and see every item match, then try to change an assessment and see the refusal. Cite
  REQ-WDS-001 to REQ-WDS-003.
- [ ] 5.2 Live check after merge on the dev instance: publish one Woo case, read the set through the
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
