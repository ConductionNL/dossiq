# Tasks: termijnbewaking-dwangsom-engine-03-pause-extension

Member 3 of 11 (code). Depends on member 02. Traces to giant Tasks 3, 4 (REQ-TERM-002, REQ-TERM-003).

## 1. PauseService (AWB 4:5/4:15)

- [x] Implement `PauseService.registerPauze(termijnInstanceId, duurDagen, motivering, documentLink)` — `lib/Service/TermijnPauseService.php::registerPauze` line 72
- [x] Extend `einddatumActueel` by `duurDagen`; set `status = gepauzeerd` — inside `registerPauze`
- [x] Record `TermijnGebeurtenis` type `pauze` with `dagenImpact = +duurDagen` — `registerPauze` writes a `pauze` event
- [x] Store pause-deadline on the instance for the daily scan to watch — `pauzeDeadline` field on `termijnInstance` schema, set by `registerPauze`
- [x] Implement `resumeAfterPauze(termijnInstanceId, aanvullingDatum)` — `TermijnPauseService::resumeAfterPauze` line 136
- [x] Compute consumed vs. unconsumed pause days; add only unconsumed days to `einddatumActueel` — `resumeAfterPauze` does diffDays(now - pauzeStart) then adjusts deadline
- [x] Set `status = lopend`; record `hervat` event; emit `termijn-pause-resumed` — `resumeAfterPauze` updates status and writes `hervat` event; event dispatch via OCP IEventDispatcher

## 2. ExtensionService (AWB 4:14)

- [x] Implement `ExtensionService.requestExtension(termijnInstanceId, motivering, newEinddatum, documentLink)` — `lib/Service/TermijnExtensionService.php::requestExtension` line 69
- [x] Validate: motivering non-empty, newEinddatum > current einddatumActueel, aantalVerlengingen < maxVerlengingen — assertions at top of `requestExtension`
- [x] On success: `aantalVerlengingen++`, `einddatumActueel = newEinddatum`, record `verleng` event with dagenImpact — implemented in `requestExtension`
- [x] Emit verlengingsbrief notification trigger — `requestExtension` dispatches `termijn-extension-granted` event picked up by TermijnNotificationService
- [x] Block second extension with error citing AWB 4:14 lid 3 — `requestExtension` throws DomainException "AWB 4:14 lid 3 — al verlengd" when aantalVerlengingen >= maxVerlengingen
- [x] Implement supervisor-approval override pathway with separate audit trail — `requestExtension($supervisorOverride = true, $supervisorUser)` writes a `verleng-override` event with grondslag "supervisor-override"

## 3. Tests

- [x] Unit test PauseService: pause extends deadline, resume consumes elapsed days proportionally — `tests/Unit/Service/TermijnPauseExtensionServiceTest.php::testPauseExtendsDeadlineBy14Days` + `testResumeConsumesElapsedDays`
- [x] Unit test ExtensionService: first extension succeeds, second blocked, override requires approval — `TermijnPauseExtensionServiceTest::testRequestExtensionFirstAttemptSucceeds`, `testRequestExtensionSecondBlocked`, `testSupervisorOverrideBypassesLimit`
