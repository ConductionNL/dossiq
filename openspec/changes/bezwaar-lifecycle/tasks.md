# Tasks: bezwaar-lifecycle

## Deduplication Check

- [ ] **T00**: Search `lib/Service/` for any existing deadline, bezwaar, suspension, or extension logic. Search `openspec/specs/` and `openspec/changes/archive/` for overlapping specs. Use `ObjectService::findObjects` (already available) for all case/objection queries — do NOT build custom DB queries. Document findings. Expected: no existing `BezwaarDeadlineService`; the frontend `bezwaar.js` store performs display-only deadline arithmetic without writing to `case.deadline`.

## Schema & Configuration

No new schemas required. All entities (`case`, `caseType`, `objection`, `caseProperty`) are defined in ADR-000 and already present in `procest_register.json`. No modifications to `procest_register.json` for schema definitions.

## Backend: Service

- [ ] **T01**: Create `lib/Service/BezwaarDeadlineService.php`.

  Inject via constructor: `ObjectService $objectService`, `IAppConfig $appConfig`, `INotificationManager $notificationManager`.

  Implement the following public methods (tag every method with `@spec openspec/changes/bezwaar-lifecycle/tasks.md#T01`):

  - `calculateDeadline(string $receivedDate, string $isoDuration): string` — parses ISO 8601 duration (e.g. `P6W`, `P12W`) and adds it to `$receivedDate`; returns ISO 8601 date string (e.g. `2026-05-13`)
  - `setDeadlineFromObjection(string $objectionId): void` — fetches the objection and its linked case; reads `caseType.processingDeadline`; calls `calculateDeadline()`; skips if `case.extensionCount >= 1`; saves `case.deadline` via `ObjectService::saveObject($register, $schema, $caseArray)`
  - `assessTimeliness(string $objectionId): void` — reads `objection.receivedDate` and the linked `decision.decisionDate` (via `objection.contestedDecision`); computes interval; sets `objection.isTimely` (bool) and `objection.timelinessAssessment` (string); saves via `ObjectService`
  - `applyExtension(string $caseId, string $reason): array` — validates: `case.extensionCount == 0`, `caseType.extensionAllowed == true`, no active suspension; adds `caseType.extensionPeriod` to `case.deadline`; increments `case.extensionCount`; appends timestamped note to `case.notes` via `ObjectService`; returns updated case array
  - `suspendDeadline(string $caseId, string $reason, string $startDate): void` — validates no active suspension exists; creates `caseProperty` object with propertyDefinition slug `bezwaar_suspension_start` and value `$startDate`; appends case note
  - `resumeDeadline(string $caseId, string $endDate): void` — validates active suspension exists; reads suspension start from `caseProperty`; calculates suspension duration in calendar days; adds duration to `case.deadline`; creates `caseProperty` for `bezwaar_suspension_end`; appends case note with recalculated deadline
  - `hasActiveSuspension(string $caseId): bool` — checks for `caseProperty` with slug `bezwaar_suspension_start` without a matching `bezwaar_suspension_end`
  - `getApproachingDeadlines(int $withinDays = 7): array` — queries `ObjectService::findObjects` for open bezwaar cases where `deadline <= today + $withinDays days` AND status is not final AND no active suspension; returns array of case objects
  - `getOverdueCases(int $withinDays = 7, int $limit = 20, int $page = 1): array` — queries cases where `deadline < today` (overdue) or deadline within `$withinDays` (at-risk); excludes final-status and actively-suspended cases; sorts overdue first (most-overdue first) then at-risk (soonest first); returns `['total' => int, 'page' => int, 'pages' => int, 'results' => array]`

## Backend: Controller

- [ ] **T02**: Create `lib/Controller/BezwaarDeadlineController.php`.

  Inject via constructor: `BezwaarDeadlineService $deadlineService`, `IGroupManager $groupManager`, `IUserSession $userSession`, `IRequest $request`.

  Tag file-level docblock and every public method with `@spec openspec/changes/bezwaar-lifecycle/tasks.md#T02`.

  Implement the following thin controller methods (≤10 lines each; all business logic in service):

  - `extend(string $caseId): JSONResponse` — checks `$groupManager->isAdmin()`, returns 403 if not admin; calls `$deadlineService->applyExtension($caseId, $reason)`; catches business-rule exceptions and returns HTTP 422 with `message`; returns 200 with updated case on success
  - `suspend(string $caseId): JSONResponse` — admin-only; calls `$deadlineService->suspendDeadline()`; returns 422 on rule violation; 200 on success
  - `resume(string $caseId): JSONResponse` — admin-only; calls `$deadlineService->resumeDeadline()`; returns 422 on rule violation; 200 with new deadline on success
  - `overdue(): JSONResponse` — authenticated (no admin requirement); reads `withinDays`, `limit`, `page` from query params; calls `$deadlineService->getOverdueCases()`; returns 200 with paginated response

## Backend: Background Job

- [ ] **T03**: Create `lib/BackgroundJob/BezwaarDeadlineJob.php` extending `OCP\BackgroundJob\TimedJob`.

  - Set interval to 86400 seconds (once daily) in constructor
  - `run()` implementation:
    1. Call `BezwaarDeadlineService::getApproachingDeadlines(7)` for at-risk cases
    2. Call a separate query (deadline < today, not final, not suspended) for overdue cases
    3. Merge and deduplicate the two lists by case UUID
    4. For each case: skip if `case.assignee` is null; check case notes for a "Deadline notificatie verzonden" entry dated today (deduplication guard); if no duplicate, send Nextcloud notification via `INotificationManager`; append dedup note to case via `ObjectService`
  - Notification parameters: app `procest`, user = case assignee UID, subject = `bezwaar_deadline_approaching` or `bezwaar_deadline_overdue` (depending on whether deadline is past), object type `case`, object id = case UUID
  - Register in `appinfo/info.php` under `<background-jobs>` if not already present
  - Tag with `@spec openspec/changes/bezwaar-lifecycle/tasks.md#T03`

## Routes

- [ ] **T04**: Add to `appinfo/routes.php` BEFORE any wildcard `{slug}` routes:

  ```php
  ['name' => 'BezwaarDeadline#overdue',  'url' => '/api/bezwaar/overdue',                      'verb' => 'GET'],
  ['name' => 'BezwaarDeadline#extend',   'url' => '/api/bezwaar/{caseId}/deadline/extend',     'verb' => 'POST'],
  ['name' => 'BezwaarDeadline#suspend',  'url' => '/api/bezwaar/{caseId}/deadline/suspend',    'verb' => 'POST'],
  ['name' => 'BezwaarDeadline#resume',   'url' => '/api/bezwaar/{caseId}/deadline/resume',     'verb' => 'POST'],
  ```

  Note: `/api/bezwaar/overdue` (static) MUST appear before `/api/bezwaar/{caseId}/deadline/...` to prevent `overdue` from being matched as a `{caseId}` value.

## Seed Data

- [ ] **T05**: Add 5 bezwaar `case` seed objects and 5 `objection` seed objects to `lib/Settings/bezwaar_seed_data.json` (create file if not present). Use `@self` envelope: `{ "@self": { "register": "procest", "schema": "case", "slug": "bezwaar-case-overdue-001" }, ...properties }`. All slugs MUST be unique.

  Required seed cases (see design.md Seed Data section for full field values):

  | Slug | Title | Deadline state |
  |------|-------|----------------|
  | `bezwaar-case-overdue-001` | Bezwaar Omgevingsvergunning Hofstraat 12 | Overdue — deadline `2026-03-15` |
  | `bezwaar-case-atrisk-001` | Bezwaar APV Marktvergunning Bakker BV | At-risk — deadline `2026-04-20` |
  | `bezwaar-case-ontrack-001` | Bezwaar WOZ-aanslag 2026 Van der Berg | On-track — deadline `2026-05-28` |
  | `bezwaar-case-extended-001` | Bezwaar Kapvergunning Lindelaan 7 | Extended — `extensionCount: 1`, deadline `2026-05-01` |
  | `bezwaar-case-suspended-001` | Bezwaar Terrasvergunning Café De Zon | Suspended — deadline `2026-06-15` |

  Extend `lib/Repair/SeedBezwaarBeroepData.php` (or create it) to call `ConfigurationService::importFromApp()` with `bezwaar_seed_data.json`. Import MUST be idempotent — use `force: false` and slug matching.

## Tests

- [ ] **T06**: Create `tests/Unit/Service/BezwaarDeadlineServiceTest.php` with PHPUnit tests:
  - `testCalculateDeadlineP6W()` — asserts 6-week addition from 2026-04-01 → 2026-05-13
  - `testCalculateDeadlineP12W()` — asserts 12-week addition
  - `testSetDeadlineSkippedWhenExtended()` — asserts deadline unchanged when `extensionCount >= 1`
  - `testApplyExtensionSuccess()` — asserts deadline advances, extensionCount becomes 1
  - `testApplyExtensionThrowsOnSecondAttempt()` — asserts exception when extensionCount is already 1
  - `testSuspendAndResume()` — asserts deadline extended by suspended days after resume

## Verification Tasks

- [ ] **V01**: Create objection via API with `receivedDate: 2026-04-01` on a P6W case type → GET case → verify `case.deadline == 2026-05-13`
- [ ] **V02**: POST `/api/bezwaar/{caseId}/deadline/extend` (valid, admin) → verify `case.deadline` advanced by `extensionPeriod`, `extensionCount == 1`, note in case notes
- [ ] **V03**: POST extend a second time → verify HTTP 422, deadline and extensionCount unchanged
- [ ] **V04**: POST extend as non-admin → verify HTTP 403
- [ ] **V05**: POST suspend then resume with endDate 9 days later → GET case → verify `case.deadline` extended by 9 days
- [ ] **V06**: GET `/api/bezwaar/overdue?withinDays=7` → verify overdue cases appear, suspended cases absent, sorted correctly, pagination fields present
- [ ] **V07**: Trigger `BezwaarDeadlineJob` (or call `run()` directly) → verify Nextcloud notification created for at-risk case assignee
- [ ] **V08**: Trigger job twice same day → verify no duplicate notification for same case
