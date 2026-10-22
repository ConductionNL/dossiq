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

- [x] 2.1 **WOO** — `WOODeadlineService` + `WOODeadlineCheckJob`: arm one `due`/`wettelijk` timer
      per Woo-verzoek (28d, verdaging +14d via `extend()`); the check job's threshold walk moves
      to the ladder; opschorting (zienswijze) onto suspend/resume. The Woo dwangsom regime
      (€15/day, max €500) stays a dossiq calculation (already carried by
      `deviatingPenaltyPaymentRegime`). Retire `WOODeadlineCheckJob`; remove its allowlist entry.
      NOTE: the WOO service owns its own notified-thresholds list — migrate it to
      `notificatiesVerstuurd`-style dedup consulted by the shared listener.
      Built 10 Oct (lane L7, decision 182: generic code, the procedure is configuration). Read off
      development first: since one-term-engine (#3539) a Woo request already runs on the generic
      term engine. Its term is the seeded `td-woo-verzoek` definition (28 days, one 14-day
      extension through `extend()`), `DeadlineCaseCreatedListener` starts the instance and arms
      the timer with the 14/7/2/0 ladder, opschorting goes through `DeadlinePauseService`, and the
      Woo dwangsom regime is the definition's `deviatingPenaltyPaymentRegime`. What the job still
      did that nothing else did was SEND the warning: `DeadlineEscalationService::notifyThreshold()`
      only logged and recorded. So the missing piece was built generic:
      `lib/Service/Termijn/TermHandlerNotice.php` tells the case's assignee on the rungs a term
      definition lists in the new `tellHandlerAt` (deadlineDefinition 1.4.0), called by
      `notifyThreshold()` after its `notificatiesVerstuurd` dedup, with the generic notifier
      subjects `term_nearing` / `term_exceeded`. The Woo seed declares `tellHandlerAt: [7, 0]`, and
      the seed now adds keys a stored definition lacks (never overwriting one), so existing
      installs get it on upgrade. An extension now drops the recorded rungs that lie ahead of the
      new end, so the handler is warned again a week before an extended end, as the job did.
      Retired: `WOODeadlineCheckJob`, its two tests, its info.xml registration and allowlist entry.
      Behaviour change: overdue is told once at the end rung, not every day. No new Woo-named class.
      Left for refactor lane R6: `WOODeadlineService::checkAndWarn()` and its private helpers and
      the `woo_deadline_*` notifier subjects have no sender any more (kept so already delivered
      notifications still render). Tests: `tests/Unit/Service/Termijn/TermHandlerNoticeTest.php`,
      `DeadlineMonitoringSeedDataServiceTest`, `DeadlineExtensionLimitTest`. Live check owed
      (live pass, decision 139): a Woo case seven days before its end notifies its assignee once.
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
- [x] 2.6 Shared: extend `TermijnTimerFiredListener` (or split per engine) on `metadata.kind`;
      each retirement carries its own fixture pair for the date arithmetic that moves.
      Split per engine (10 Oct, lane L7): each engine has its own fired listener keyed on its own
      `metadata.source` (`dossiq-advice`, `dossiq-bezwaartermijn`, `dossiq-dso`,
      `dossiq-milestone`), all registered in `TermijnTimerRegistrar`, and each timer test pins the
      days the retired job acted on (`AdviceTimerTest`, `BezwaarArchiveTimerTest`,
      `DsoDeadlineTimerTest`, `MilestoneStallTimerTest`). The saved-object plumbing they share is
      `Listener/Support/SavedObjectPayload`.

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
      to two, then to one when 2.1 retired `WOODeadlineCheckJob`. Left: `PauseChaseJob`, the
      documented fallback while OpenRegister is an optional runtime dependency. It computes no
      threshold itself (it calls `PauseChaseService::sweep()`), and it cannot go before the engine
      becomes a required dependency, so the list stops at one, not empty, until that phase lands.
      The `->diff(` sweep, part 1 of 4 (12 Oct, lane L7, REQ-TOT-007): `lib/Service/Term/DayCount.php`
      is the one place that counts the days between two dates (`between()` for a span, `signed()`
      for days left or overdue), and `WorkingDayRoll::daysBetween()` uses it for calendar mode.
      Read off development first: 31 `->diff(` sites in 27 files, all under `lib/Service`. Moved in
      this part, the deadline countdowns outside the calculation classes: `ComplaintService`
      (alert window), `Doorlooptijd/CaseEnricher` (days left, throughput) and
      `Milestone/StalledCaseDetector` (days overdue), each pinned by a characterisation test that
      was green on the old code first. `tests/Unit/Architecture/DayCountIsTheOnlyDiffTest.php`
      refuses a new site; `day-count.allowlist.json` lists the 23 files still to move, each with
      its part: timer arm mappings (4), term calculations (7), reporting and age (11), and
      `WOODeadlineService`, whose countdown has no caller since 2.1 and goes with the refactor
      programme. This task closes when the list holds only that last entry or none.
      Part 2 of 4 (12 Oct, lane L7): the four timer arm mappings ask `DayCount` for the days from
      their anchor to their end (`Advice/AdviceTimer`, `Beschikking/BezwaarArchiveTimer`,
      `Dso/DsoDeadlineTimer`, `Milestone/MilestoneStallTimer`). Their own tests already pinned the
      armed SLA to the day; each was mutation-checked on the old code first (a count one day off
      turns them red). The list is at 19.
      Part 3 of 4 (12 Oct, lane L7): the term calculations ask `DayCount`: `CaseTermsService` (term
      consumed, days left), `DeadlinePauseService` (days of a pause used), `PhaseTermService`
      (days in a phase), `Term/FirstResponseOutcome` (calendar overrun) and
      `AanvullingsverzoekService::daysOpen()`. Three were pinned by their own tests
      (mutation-checked on the old code); term consumed and days open were not, and got
      characterisation tests first (`PlannedTermTest`, `AanvullingsverzoekServiceTest`).
      `DeadlineExtensionService` and `TermijnTimerService` stay on the list: both sit at the PHPMD
      coupling limit, so one more collaborator fails the build, and both are calculation classes
      that own their day math. They move when `method-decomposition` splits them. The list is at 14.
      Part 4 of 4 (12 Oct, lane L7): the reporting and age counts ask `DayCount`: complaint
      resolution days, the two average durations of the deadline report, the days an imported
      extension adds, incident reporting delay, days of silence before a case closes, open workload
      age, days since assignment in the personal queue, days left in a deletion window and the
      interest days of a recovery. Six of them had no test that a wrong number turned red and got
      characterisation tests first. `KpiAggregationService` and `WorkQueueService` stay on the list
      for the same coupling reason as the two above.
      Where the sweep ends: 26 of 31 sites moved, and the list holds 5 files with one site each.
      Four wait on `method-decomposition`; `WOODeadlineService` goes with the refactor programme.
      What keeps this task open is its second half: the TimedJob allowlist still names
      `PauseChaseJob`, which cannot go before OpenRegister is a required dependency.
      Part 5 (22 Oct, R22-RECORD): the two age counts the list said could move do:
      `KpiAggregationService::daysBetween()` (signed days a closed case took, pinned by
      `KpiAggregationServiceTest`'s averages, including a negative span) and `UrgencyScorer`'s idle
      days (pinned by `UrgencyScorerTest` and `WorkQueueServiceTest`) ask `DayCount`, as a default
      collaborator so neither constructor's contract widens. The list is at 3: two wait on
      `method-decomposition`, `WOODeadlineService` goes with the refactor programme.

## Verify

- [x] V.1 `openspec validate termijnbewaking-op-engine-timers --strict` exits 0 (10 Oct, openspec 1.12.0).
- [x] V.2 After each phase: the structural test's allowlist shrank; hydra gates green on the diff.
      10 Oct (lane L7, 2.1): allowlist 2 -> 1; `run-hydra-gates.sh --scope-to-diff --base origin/development`
      on 64f9bbe06: three reds, all inherited (gate 23 names tenant and audit files this diff does not
      touch, gate 53 cannot resolve ajv locally, gate 101 names the `dossiqIntegration` and
      `wooRefusalGround` demo data). Phase 2 is complete; phase 4 stops at 4.2.
