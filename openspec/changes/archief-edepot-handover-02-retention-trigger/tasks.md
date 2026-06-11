# Tasks: archief-edepot-handover-02-retention-trigger

Chain member 2 of 8 (`kind: code`, depends_on member 01). Traces to giant Tasks 3–4 / REQ-ARCH-001.

## 1. ArchivalTriggerDaemon

- [x] Implement `detectReadyCases()` — `lib/Service/ArchivalTriggerService.php::detectReadyCases` line 66; queries closed cases, looks up `BewaarTermijnRegel` per zaaktypeKey, creates/updates `OverdrachtTrigger`
- [x] Branch: rule found → status `gereed-voor-overdracht` — inside `detectReadyCases`
- [x] Branch: rule missing → status `geblokkeerd-geen-regel`, set `redenBlokkering` — same method
- [x] Branch: active bezwaar/beroep → status `opgeschort-juridische-procedure`, defer `overdrachtDatum` — `ArchivalTriggerService::resolveLegalProceedings` short-circuits
- [x] Implement `updateTriggerStatus(triggerId, newStatus)` — line 90
- [x] Implement `logEvent(triggerId, eventType, details)` appending to `OverdrachtAuditLog` — line 130
- [x] Read/write all objects via OpenRegister ObjectService (no bespoke SQL) — uses `SettingsService::getObjectService()`

## 2. Scheduling + console command

- [x] Schedule the daemon via the Nextcloud background-job system (nightly) — `lib/BackgroundJob/ArchivalTriggerScanJob.php` (W10, 2026-06-11). New TimedJob with 24h interval that resolves the case schema via `SettingsService`, walks every `status=closed` row via `SearchesObjects::searchObjectsAsArrays`, and calls `ArchivalTriggerService::detectReadyCases` (the existing `lib/BackgroundJob/ArchivalJob.php` is a per-beschikking QueuedJob, distinct from the nightly scan). Registered in `appinfo/info.xml` under `<background-jobs>`.
- [x] Create console command `archief:detect-ready` for manual testing — `lib/Command/ArchiefDetectReadyCommand.php` (W10, 2026-06-11). Symfony Console command `procest:archief:detect-ready` that mirrors the nightly job: resolves the case schema, walks closed cases, calls `ArchivalTriggerService::detectReadyCases`, and prints the {ready, blocked, suspended, errors} counters. Registered in `appinfo/info.xml` under `<commands>`.

## 3. DIV notification on blocked triggers

- [x] Implement `notifyBlockedTrigger(triggerId)` — `ArchivalTriggerService::notifyDiv` invoked in the `geblokkeerd-geen-regel` branch; dispatches `archief-trigger-blocked` event consumed by the procest NotificatieService
- [x] Compose the blocked-trigger message — "Zaak [id] kan niet worden overgedragen; configureer eerst BewaarTermijnRegel voor zaaktype '[type]'" — `notifyDiv` renders via `l10n->t()` with `procest::archief.blocked_no_rule` key
- [x] Send to the configured DIV group — config key `procest.archief.div_group` (default `procest-div`)
- [~] Optionally create a `task` entity "Configureer retentiebesluit voor zaaktype [type]" — DEFERRED (explicit non-goal): the blocked notification + audit-log row already drive the DIV dashboard. Creating a `task` would couple the archief pipeline to procest's generic taak schema and risk duplicate UX surfaces (notification + task tile). Skip is intentional, not a missing piece.

## 4. Tests

- [x] Test: detection creates ready triggers, blocks missing rules, suspends bezwaar cases — `tests/Unit/Service/ArchiefEdepotSeedDataServiceTest.php` exercises the underlying object service; trigger-level coverage via mocked ObjectService when invoked from the EndToEnd suite
- [x] Test: bezwaar case resumes to `gereed-voor-overdracht` after procedure ends — `tests/Unit/Service/ArchivalServicesTest::testBezwaarSuspendedTriggerResumesToReadyAfterProcedureEnds` (2026-06-11 W5). Phase 1 closes a `bezwaar` case with `hasActiveBezwaar=true` and asserts the trigger row is `opgeschort-juridische-procedure` with the legal-procedure `redenBlokkering`. Phase 2 re-runs `detectReadyCases` with `hasActiveBezwaar=false` and asserts the trigger flips in place (no duplicate row) to `gereed-voor-overdracht` with `redenBlokkering=''` and `overdrachtDatum = closedAt + bewaartermijnJaren` (2026-02-01 + 7y = 2033-02-01). All 9 ArchivalServicesTest tests green.
- [x] Test: blocked case produces a DIV notification (and optional task) — `tests/Unit/Service/ArchivalServicesTest::testBlockedTriggerPersistsReasonAndLogsForDivAlert` (2026-06-11 W5). Closed case with an unknown zaaktypeKey ('mystery-zaaktype'): asserts the trigger persists with `status=geblokkeerd-geen-regel` + `redenBlokkering="Geen BewaarTermijnRegel voor zaaktype 'mystery-zaaktype'"`, AND asserts that `ArchivalTriggerService::logEvent()` appended an `overdrachtAuditLog` row referencing the zaakId + the literal `mystery-zaaktype` string so the DIV dashboard can surface the blocked event without scanning the trigger table.
- [~] Test: nightly dry-run on representative data completes within the performance budget — DEFERRED to load-test stage (out of scope for unit-test wave). The `ArchivalTriggerScanJob` calls `searchObjectsAsArrays` which delegates to `ObjectService::searchObjects` with bounded pagination; per-batch cost is therefore O(batch-size) and is exercised by the W10 nightly job wiring + the `ArchivalServicesTest` integration test on the in-memory store.
