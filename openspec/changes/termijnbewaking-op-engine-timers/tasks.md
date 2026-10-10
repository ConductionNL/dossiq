# Tasks — termijnbewaking on engine timers

## Phase 1: core termijn engine (implemented in this change)

- [x] 1.1 Lazy engine resolution: the existing generic
      `SettingsService::getOpenRegisterClass()` resolves
      `OCA\OpenRegister\Service\Flow\Timer\FlowTimerService` at call time, null on absence
      (D-7) — no new resolver needed.
- [x] 1.2 `lib/Service/TermijnTimerService.php` — the arm mapping (D-1): `armBeslistermijn()`,
      `armHersteltermijn()`, `suspendBeslistermijn()` (basis `Awb 4:5`), `resumeBeslistermijn()`,
      `extendBeslistermijn()` (standard → `extend()`, supervisor → `extendWithOverride()`),
      `cancelForInstance()`. Every call degrades to a logged no-op when the engine is absent.
- [x] 1.3 `TermijnService::createTermijnInstance()` arms the timer and stores `engineTimerId`;
      `markTermijnCompleted()` cancels every open timer of the instance with a recorded reason.
- [x] 1.4 `DeadlinePauseService` maps opschorting onto engine suspend/resume (D-2) and arms/ignores
      the hersteltermijn helper timer; pause bookkeeping fields (`pauzeStartDatum`,
      `pauzeDuurDagen`) are now DECLARED on the deadlineInstance schema so OpenRegister stops
      silently dropping them (fixes the resume consumed-days arithmetic in production).
- [x] 1.5 `DeadlineExtensionService` mirrors verdaging to the engine after the domain refusal
      rules pass (standard vs supervisor override).
- [x] 1.6 `lib/Listener/TermijnTimerFiredListener.php` consumes `FlowTimerFiredEvent` (D-4);
      registered in `ObjectListenerRegistrar`.
- [x] 1.7 `DwangsomCalculationService::accrueThrough()` replaces `calculateDaily()` (D-3):
      derived, catch-up-safe accrual; `stopForBeschikking()` settles through the stop moment first.
- [x] 1.8 RETIRE `DailyDeadlineScanJob` + `DeadlineDailyScanService` (delete classes, drop the
      info.xml job registration, delete the scan test).
- [x] 1.9 `lib/Repair/ArmTermijnEngineTimers.php` (D-5), registered in info.xml post-migration.
- [x] 1.10 Schema: add `engineTimerId`, `pauseTimerId`, `pauzeStartDatum`, `pauzeDuurDagen`,
      `voltooiDatum` to `deadlineInstance` in `lib/Settings/register.d/60-termijnbewaking.json`.
- [x] 1.11 Structural test `tests/Unit/Architecture/TimedJobDeadlineThresholdTest.php` (D-6).
- [x] 1.12 Fixture pairs proving unchanged AWB date arithmetic: opschorting pair (D-2), verdaging
      day impact, dwangsom tier series (accrueThrough day N == N legacy daily ticks), threshold
      buckets == ladder rungs.
- [x] 1.13 Engine-call stubs for the unit bootstrap (`FlowTimer`, `FlowTimerService`,
      `FlowTimerFiredEvent`) mirroring the REAL signatures (a stub that agrees with the caller
      cannot fail).
- [x] 1.14 Quality: `php -l`, phpcs, phpmd (per subdir), psalm, phpstan, phpunit, hydra gates
      `--scope-to-diff`; grep `tests/e2e` for assertions on the retired job (none exist — the e2e
      surface only touches the termijn dashboard page shell).

## Phase 2: sibling engines (staged)

> **Re-verified 2026-09-10 against `development`.** Phase 1 still holds and
> phases 2 to 4 are untouched. All five jobs these phases exist to retire are
> present in `lib/BackgroundJob/`: `WOODeadlineCheckJob.php`,
> `BezwaarTermijnJob.php`, `DsoDeadlineJob.php`, `AdviceDeadlineJob.php` and
> `BottleneckDetectionJob.php`, and all five are still registered in
> `appinfo/info.xml`, so they are live cron and not dead machinery. Nothing
> external blocks this: the OpenRegister timer seam phase 1 already uses is
> the same one phases 2 and 3 need. It is unstarted, and it is the largest
> single piece of unstarted work in this app's open changes.

- [ ] 2.1 **WOO** — `WOODeadlineService` + `WOODeadlineCheckJob`: arm one `due`/`wettelijk` timer
      per Woo-verzoek (28d, verdaging +14d via `extend()`); the check job's threshold walk moves
      to the ladder; opschorting (zienswijze) onto suspend/resume. The Woo dwangsom regime
      (€15/day, max €500) stays a dossiq calculation (already carried by
      `deviatingPenaltyPaymentRegime`). Retire `WOODeadlineCheckJob`; remove its allowlist entry.
      NOTE: the WOO service owns its own notified-thresholds list — migrate it to
      `notificatiesVerstuurd`-style dedup consulted by the shared listener.
- [x] 2.2 **Bezwaar** — `Beschikking/BezwaarTermijnScheduler` + `BezwaarTermijnJob`: the
      bezwaartermijn (6 weeks after bekendmaking, AWB 6:7) is anchor-shaped — arm with
      `anchorEvent: bekendmaking`, and use `supersede()` when the bekendmaking moves. Retire
      `BezwaarTermijnJob`; remove its allowlist entry. NOTE: the scheduler currently advances
      beschikking status from cron; that transition becomes a listener consuming the timer fire,
      keeping the state machine in `StateMachineService`.
      Built 10 Oct (lane L7): `lib/Service/Beschikking/BezwaarArchiveTimer.php` arms one timer per
      active `bezwaarTrigger`, anchored on the bekendmaking (`anchorEvent: bekendmaking`) and
      breaching at the start of its `archiveDate`, the first day the job acted
      (`archiveDate <= today`). A moved bekendmaking or archive date re-arms through cancel-then-
      arm (the same supersede shape as the advice timer, rather than the engine's in-place
      `supersede()`). The job's act moved to `BezwaarArchiveTrigger::process()`, which reads the
      trigger FRESH so an objection registered after arming still prevents the archive, and
      archives through `BeschikkingService::archive()` (its state machine). The scheduler never
      advanced status itself on this path; the job did, and that is now the timer-fire listener
      `BezwaarArchiveTimerFiredListener`. `BezwaarArchiveTimerListener` syncs on saves,
      `lib/Repair/ArmBezwaarArchiveTimers.php` arms the triggers running at upgrade. Job, its two
      tests and its allowlist entry are gone. Live check owed (live pass, decision 139).
- [x] 2.3 **DSO** — `DsoDeadlineJob` advances case status from cron: replace with an armed timer
      per DSO-zaak and a `FlowTimerFiredEvent` consumer that drives the SAME
      `StatusTransitionService` path a user action takes (no cron-only transition code). Retire
      the job; remove its allowlist entry.
      Built 10 Oct (lane L7). Correction to the task text, read off the job: DsoDeadlineJob never
      moved a status; it notified the assignee in a warning and a critical band and, on overdue,
      set `deadlineOverdue` with one journal entry, so there was no transition to route.
      `lib/Service/Dso/DsoDeadlineTimer.php` arms one timer per open DSO case (`dsoStatus`
      submitted/in_handling) breaching on the deadline day (the job called day 0 overdue), with
      the two bands as `preBreach` rungs in businessDays read from the `dso_deadline_warning_weeks_*`
      settings as working days, as the job read them. Subject `<case>:dso`, because status dwell
      timers are cancelled by subject on the case id. One notification per band instead of one
      per day. `DsoDeadlineActs` (fresh read; a decided case is never marked), listener, fired
      listener, `ArmDsoDeadlineTimers` repair. Job, two tests, allowlist entry gone.
- [x] 2.4 **Vergadering** — DELIVERED BY RETIREMENT, not by migration: the wave-5 status sweep
      (`openspec/changes/case-status-onto-engine-lifecycle`) found the engine dead — the job
      scanned for cases with a literal `status: 'planned'`, which `case.status` (a statusType
      reference) can never hold, and the only writer of such cases was removed earlier. The job,
      `VergaderingCaseService`, their tests and the allowlist entry are gone; there was nothing
      to arm a timer for.
- [x] 2.5 **Advice** — `AdviceDeadlineJob` (the fifth sibling, found during phase 1's structural
      sweep): advice-request deadlines onto armed timers; retire the job; remove its allowlist
      entry. Built 10 Oct (lane L7): `lib/Service/Advice/AdviceTimer.php` arms one `due`/`none`
      timer per open `adviesAanvraag`, anchored at midnight today with an SLA of the days left
      plus one, so the `slaBreached:0` rung lands on the first day after the deadline and the
      `preBreach:<reminderDays+1>` rung on `deadline - advice_reminder_days`, the job's two days
      (fixture: `tests/Unit/Service/Advice/AdviceTimerTest.php`). `AdviceTimerListener` re-syncs
      on every save that moves the status or the deadline (supersede: cancel, then arm);
      `AdviceTimerFiredListener` turns the rungs into `dispatchReminder()` and `expireAdvice()`
      as the background service account; `lib/Repair/ArmAdviceTimers.php` arms the requests open
      at upgrade and expires the overdue ones. The job, its test and its allowlist entry are
      gone; wiring asserted from `tests/Unit/AppInfo/TermijnTimerRegistrarTest.php`. Live check
      owed (live pass, decision 139): an advice request with a deadline 5 days out arms a timer
      (`occ` / OR flow timers list) whose reminder fires on day 2 and expiry on day 6.
- [ ] 2.6 Shared: extend `TermijnTimerFiredListener` (or split per engine) on `metadata.kind`;
      each retirement carries its own fixture pair for the date arithmetic that moves.

## Phase 3: milestones (staged)

- [x] 3.1 `Milestone/StalledCaseDetector` + `BottleneckDetectionJob`: the stalled-threshold
      becomes an armed `due`/`none` timer per active milestone (SLA in `businessDays` against the
      seeded `nl-national` calendar); detection-on-cron becomes rung fires. Retire
      `BottleneckDetectionJob`; remove its allowlist entry.
      Built 10 Oct (lane L7): `StalledCaseDetector::waitingOn()` names the milestone a case waits
      on with its scheduled deadline (the same row the stalled list reports, without the lateness
      filter), and `lib/Service/Milestone/MilestoneStallTimer.php` arms one timer per case on it,
      breaching the day after the deadline (`daysOverdue > 0`). It re-syncs on case saves that move
      status, start date or case type and on every milestone-record save. `MilestoneStallActs`
      tells the assignee once, only when the case still waits late on the armed milestone. The
      SLA stays dossiq's working-day count; the engine `SlaCalculator`/`nl-national` calendar is
      task 3.2. Job, its test and allowlist entry gone.
- [x] 3.2 `MilestoneService`: replace the app-local working-day math with the engine
      `SlaCalculator` + `WorkingCalendarService` (fixture pair: same business-day counts across a
      weekend + Dutch national holiday).
      Found done underneath this task (checked 10 Oct, lane L7): `WorkingDayCalculator`, which
      `MilestoneSchedule` and `StalledCaseDetector` count with, asks OpenRegister's administered
      calendar through `Termijn\WorkingDayRoll::worksOn()` since 2026-09-19 and keeps its Dutch
      list only as the logged fallback (`tests/Unit/Service/WorkingDaysAreAdministeredTest.php`).
      The fixture pair at the milestone level: `tests/Unit/Service/Milestone/MilestoneScheduleOnTheCalendarTest.php`
      (Ascension and Whit Monday 2026 land the same on the calendar as on the list; an
      administered closure day moves the milestone).

## Phase 4: KCC (staged)

- [x] 4.1 Retire `Kcc/SlaCalculator` (the name-for-name duplicate): `CallbackService` and the KCC
      routing consult the engine `SlaCalculator` against the organisation calendar; the KCC
      callback SLA (2 working hours) becomes `{value: 2, unit: hours}` timers on the callback
      object with the KCC ladder as escalationRules. Fixture pair: identical due moments for the
      documented KCC cases before and after.
      Built 10 Oct (lane L7), read off the code first: the only production caller of
      `Kcc/SlaCalculator` was `CallbackService::applyAttempt()`, for the retry backoff. Its channel
      SLA tables (`deadlineFor()`, `isBreached()`) had no caller in `lib/` or `src/`, and no KCC
      routing consults an SLA in dossiq any more (the agent panel moved to pipelinq), so there was
      no SLA to arm a timer for and no fixture pair to keep. The backoff moved to
      `lib/Service/Kcc/CallbackRetrySchedule.php` (`tests/Unit/Service/Kcc/CallbackRetryScheduleTest.php`,
      ported from the retired test); the calculator and its test are gone; the date audit and the
      no-local-calendar control file follow.
- [ ] 4.2 Sweep `lib/` for remaining `->diff(` deadline math outside the allowlisted calculation
      classes; tighten the structural test's allowlist to empty.
      Partly (10 Oct, lane L7): the allowlist in `TimedJobDeadlineThresholdTest` shrank from six
      to two. Left: `WOODeadlineCheckJob` (task 2.1, waits on #3539 and the Woo lanes, which own
      the Woo services) and `PauseChaseJob` (the documented fallback while OpenRegister is an
      optional runtime dependency).

## Verify

- [ ] V.1 `openspec validate termijnbewaking-op-engine-timers --strict` exits 0.
- [ ] V.2 After each phase: the structural test's allowlist shrank; hydra gates green on the diff.
