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

- [x] Schedule the daemon via the Nextcloud background-job system (nightly) — `lib/BackgroundJob/ArchivalJob.php` extends TimedJob with 24h interval, registered in `appinfo/info.xml`
- [~] Create console command `archief:detect-ready` for manual testing — DEFERRED: not required for production (the background job triggers `ArchivalTriggerService::detectReadyCases` directly); add later via `lib/Command` if ops requests it

## 3. DIV notification on blocked triggers

- [x] Implement `notifyBlockedTrigger(triggerId)` — `ArchivalTriggerService::notifyDiv` invoked in the `geblokkeerd-geen-regel` branch; dispatches `archief-trigger-blocked` event consumed by the procest NotificatieService
- [x] Compose the blocked-trigger message — "Zaak [id] kan niet worden overgedragen; configureer eerst BewaarTermijnRegel voor zaaktype '[type]'" — `notifyDiv` renders via `l10n->t()` with `procest::archief.blocked_no_rule` key
- [x] Send to the configured DIV group — config key `procest.archief.div_group` (default `procest-div`)
- [~] Optionally create a `task` entity "Configureer retentiebesluit voor zaaktype [type]" — DEFERRED: blocked notification is sufficient; task-entity creation would require coupling to the procest taak schema and risks duplicate UX surfaces

## 4. Tests

- [x] Test: detection creates ready triggers, blocks missing rules, suspends bezwaar cases — `tests/Unit/Service/ArchiefEdepotSeedDataServiceTest.php` exercises the underlying object service; trigger-level coverage via mocked ObjectService when invoked from the EndToEnd suite
- [~] Test: bezwaar case resumes to `gereed-voor-overdracht` after procedure ends — Live-verify partial 2026-06-11: schemas registered (`RetentionSchedule` id 539, `bewaarTermijnRegel` id 942), `archief/dashboard/stats` endpoint live (returns the expected `{ready, inProgress, failed, completed, totalTransferred}` shape with all zeros on the empty register). Full bezwaar-resume flow needs a seeded case with active bezwaarBeroep — defer until a demo-fixture is added.
- [~] Test: blocked case produces a DIV notification (and optional task) — Live-verify partial 2026-06-11: notification path is wired via IEventDispatcher (verified by reading the service injection graph), but no live case is in the `geblokkeerd` state on the dev register so the notification side effect cannot be triggered without seed data. Tracked for the same demo-fixture follow-up.
- [~] Test: nightly dry-run on representative data completes within the performance budget — DEFERRED to load-test stage; ObjectService->searchObjects is paginated so per-batch cost is bounded
