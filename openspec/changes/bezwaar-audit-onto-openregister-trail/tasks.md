# Tasks: bezwaar-audit-onto-openregister-trail

Gate 23 rule 2. Statutory: Awb art. 7:2 to 7:13, AVG art. 6. Build rules:
`openspec/woo-build-rules.md`.

Every task names the requirement it meets and the test that proves it. A test marked
**fails today** must be run on `origin/development` before the change and seen red; note the
failure line in the PR body. The 17 call sites are numbered in `proposal.md`.

Read the real `AuditTrailMapper::createAuditTrailEntry()`, `AuditTrailMapper::findAll()` and
`ObjectService::find()` on `ConductionNL/openregister` branch `development` (or in `vendor/`) before
doubling them. `createAuditTrailEntry()` takes an `ObjectEntity`, not a uuid, and stamps the time
itself. Resolve OpenRegister classes the way `TenantAuditTrailService::getAuditTrailMapper()` does.

## 1. The writer

- [x] 1.1 Rewrite `lib/Service/Bezwaar/BezwaarAuditTrail.php` as a writer onto OpenRegister.
  `record(string $register, string $schema, string $objectUuid, string $event, array $payload, string $tag = ''): void`
  resolves the `ObjectEntity` through `ObjectService::find()` and calls
  `createAuditTrailEntry(object: ..., action: 'dossiq.bezwaar.' . $event, context: {event, tag?, actor, at, payload})`.
  It throws a `RuntimeException` when OpenRegister is absent, the object does not resolve, or the
  write throws. `resolveActor()` and the tag constants stay (REQ-BAT-001, REQ-BAT-003).
  - **fails today**: `tests/Unit/Service/Bezwaar/BezwaarAuditTrailTest.php`
    `testRecordWritesOneTaggedRowThroughTheMapper`, `testTheContextKeepsTheEntryKeyOrder`,
    `testTheActorComesFromTheSessionNeverThePayload` and `testRecordThrowsWhenOpenRegisterIsAbsent`.
  - Gate check: gate 23 prints `BezwaarAuditTrail.php` under "writes through OpenRegister's
    AuditTrailMapper, compliant, not counted".
- [x] 1.2 Remove `append()`. No code under `lib/` writes the `auditTrail` key of a `hearingSession` or
  `bacAdviceRequest` again, and the five `$current['auditTrail']` reads go (REQ-BAT-001).
  - **fails today**: `tests/Unit/Architecture/NoEmbeddedBezwaarAuditWriteTest.php`
    `testNoBezwaarServiceWritesTheAuditTrailProperty`.

## 2. Hearings (call sites 1 to 11)

- [x] 2.1 `schedule()` (site 2): save, then record `hearing-scheduled` with awb-art-7:2 on the saved
  session; on a failed entry delete the saved session and throw (REQ-BAT-001, REQ-BAT-003).
  - **fails today**, through the caller:
    `tests/Unit/Listener/BezwaarHearingScheduledListenerTest.php`
    `testASeededHearingGetsAnAwb72RowOnItsOwnTrail`, built on the real `HearingService` and
    `BezwaarAuditTrail` with only the OpenRegister seams doubled.
  - **fails today**: `tests/Unit/Service/HearingServiceTest.php`
    `testAHearingWhoseEntryCannotBeWrittenIsDeletedAndRefused`.
- [x] 2.2 `waive()` (site 3): the same shape for `hearing-waived` with awb-art-7:3. No caller in
  `lib/` today, so the test drives the method (REQ-BAT-001, REQ-BAT-003).
  - **fails today**: `HearingServiceTest` `testAWaiverWritesAnAwb73RowWithItsReason`.
- [x] 2.3 `recordAttendance()` and `HearingMinutesRecorder::appendLateCorrectionAudit()` (sites 4,
  10, 11): record each late correction with awb-art-7:7 before the attendance patch; a failed patch
  writes `attendance-late-correction-not-applied` and throws (REQ-BAT-001, REQ-BAT-003).
  - **fails today**, through the caller:
    `tests/Unit/Controller/BezwaarHearingControllerRecordAttendanceTest.php`
    `testALateCorrectionWritesAnAwb77RowWithItsReason`.
- [x] 2.4 `addMinutes()` and `guardRecordingConsent()` (sites 5, 6, 8, 9): `audio-upload-denied`
  with avg-art-6 is recorded and the upload refused even when the entry fails (logged at error with
  the full entry); `verslag-recorded` with awb-art-7:7 is recorded before the minutes patch
  (REQ-BAT-001, REQ-BAT-003).
  - **fails today**: `HearingServiceTest` `testARefusedAudioUploadIsRecordedUnderAvgArt6` and
    `testMinutesAreRecordedBeforeThePatch`.
- [x] 2.5 Sites 1 and 7: the constructors keep `BezwaarAuditTrail`; `HearingMinutesRecorder` no
  longer returns an array for the caller to save (REQ-BAT-001).
  - **fails today**: `tests/Unit/Service/Bezwaar/HearingMinutesRecorderTest.php`
    `testTheRecorderReturnsNoAuditArray`.

## 3. The advisory committee (call sites 12 to 17)

- [x] 3.1 `assignToCommittee()` (site 13): save, then record `panel-member-added` on the saved
  request; on a failed entry delete it and throw. `autoAssignDefaultCommittee()` keeps answering
  `null` on a throw, so no unrecorded request survives (REQ-BAT-001, REQ-BAT-003).
  - **fails today**, through the caller:
    `tests/Unit/Listener/BezwaarAdviceRequestedListenerTest.php`
    `testAnAutoAssignedRequestCarriesItsPanelRowOnItsOwnTrail` and
    `testAnAssignmentWhoseEntryFailsLeavesNoRequest`.
- [x] 3.2 `transitionAdviceStatus()` (sites 14, 16, 17): `independence-check-failed` is recorded and
  the transition refused even when the entry fails; `advice-signed-by-chair` is recorded before the
  status patch, with the chair from `resolveActor()`; a failed patch writes
  `advice-signed-by-chair-not-applied` and throws. No caller in `lib/` today (REQ-BAT-001,
  REQ-BAT-003).
  - **fails today**: `tests/Unit/Service/Bezwaar/AdvisoryCommitteeServiceTest.php`
    `testASignedAdviceIsRecordedBeforeTheStatusMoves`,
    `testAFailedStatusWriteLeavesANotAppliedRow` and
    `testAFailedIndependenceCheckIsRecordedAndRefused`.
- [x] 3.3 `recordCouncilDeviation()` (site 15): record `council-deviation-recorded`; a failed entry is
  reported to `DecisionConcludedListener`, which logs it at error level with the full entry
  (REQ-BAT-001, REQ-BAT-003).
  - **fails today**, through the caller: `tests/Unit/Listener/DecisionConcludedListenerTest.php`
    `testADeviatingDecisionWritesAnAwb713RowOnTheAdviceRequest` and
    `testAFailedDeviationEntryIsLoggedWithTheEntry`.

## 4. The entries already stored

- [x] 4.1 Add `lib/Repair/CopyEmbeddedBezwaarAuditTrail.php` and register it under
  `<post-migration>` in `appinfo/info.xml`. It copies every entry of every `hearingSession` and
  `bacAdviceRequest` `auditTrail` in order, actor `system`, context with the original keys plus
  `migratedFrom: auditTrail` and `migratedIndex`. It finds rows it already wrote with
  `AuditTrailMapper::findAll(filters: ['object_uuid' => ..., 'action' => 'dossiq.bezwaar.*'])` and
  skips their `migratedIndex`. It never changes the array. An object it cannot write is reported by
  uuid and the run goes on (REQ-BAT-004).
  - **fails today**: `tests/Unit/Repair/CopyEmbeddedBezwaarAuditTrailTest.php`
    `testEveryEntryArrivesOnceInOrderWithItsOriginalTimeAndActor`, `testASecondRunWritesNothing`,
    `testTheArrayIsNotChanged` and `testAnObjectThatCannotBeWrittenIsReportedAndTheRunGoesOn`.
  - Through the caller: a test that reads `appinfo/info.xml` and finds the step under
    `<post-migration>`, `testTheCopyStepIsRegistered`.
- [ ] 4.2 In both `lib/Settings/dossiq_register.json` and `lib/Settings/dossiq_mock_register.json`,
  describe `auditTrail` on `hearingSession` and `bacAdviceRequest` as the frozen record written before
  this change and copied to OpenRegister's audit trail; bump the register `info.version`
  (REQ-BAT-004).
  - **fails today**: `tests/Unit/Settings/BezwaarAuditTrailPropertyIsFrozenTest.php`
    `testBothSchemasDescribeTheArrayAsFrozen`.

## 5. The screen and the live check

- [ ] 5.1 `tests/e2e/bezwaar-awb-history.spec.ts`: request advice on a bezwaar with a default
  committee configured, open the advice request, open History, and see the
  `dossiq.bezwaar.panel-member-added` entry with its actor. Cite REQ-BAT-002.
- [ ] 5.2 Live check after merge on the dev instance: one bezwaar with a scheduled hearing. Read the
  `hearingSession` trail through OpenRegister's audit trail API filtered on
  `action=dossiq.bezwaar.*`, and run the repair step once on an instance with old entries. Record
  both in the issue.

## 6. Verify and deliver

- [ ] 6.1 `TMPDIR` set to a sibling directory beside the clone, never inside it. While building, run
  only the unit tests of touched classes with
  `./vendor/bin/phpunit -c phpunit-unit.xml --no-coverage --filter '<Class>'` and judge by the
  `Tests:` line, because a green suite exits 1 without a coverage driver.
- [ ] 6.2 Before push, once: `COMPOSER_PROCESS_TIMEOUT=0 composer check:strict`, then `npm run lint`
  and any other leg `code-quality.yml` requires. Then
  `scripts/run-hydra-gates.sh --base origin/development`, count the gates that ran, and paste gate
  23's output: rule 2 prints `BezwaarAuditTrail.php` as compliant. The coverage guard needs tests for
  every added statement.
- [ ] 6.3 One PR, `--base development`. Merge development in, never rebase. No `Co-Authored-By` on
  any commit. Done means merged on `development` with CI green.
