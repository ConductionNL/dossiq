# Tasks: pause-reason-with-chasing

Tier: V1. Kind: code. Row 2.25. Waits on `termijnbewaking-op-engine-timers`
phase 1 (shipped) and reuses its helper timer.

- [x] 1.1 `lib/Settings/register.d/66-pause-reason.json`: `caseType.pauseReasons`
  (D-1), the reason and the counters on `deadlineInstance`, the waiting facts on
  `case`, and `chased` / `chase-escalated` on the event type.
  - A fragment of its own rather than an edit to `60-termijnbewaking.json`, per
    ADR-037, because several dossiq lanes build against that file at once.
  - The vocabulary is a declared list ON the case type, the way
    `caseType.attentionMarkers` already is, rather than a second schema with
    rows. A schema would need its own seeder, its own lifecycle and its own
    admin surface to say what a ten-line declaration says.
  - The two reasons the change asked to seed are declared as `examples` on the
    property: nothing in this repo seeds a demo case type, and adding a seeder
    is a change of its own.
  - `@spec openspec/changes/pause-reason-with-chasing/specs/termijn-pause-extension/spec.md`
- [x] 1.2 `lib/Service/Pause/ChaseSchedule.php`: the interval, the budget and
  the rung offsets, as pure arithmetic over the instance.
  - fixture pair: 14 days, interval 5, budget 2 gives offsets 9 and 4; the
    working-day interval skips the weekend.
- [x] 1.3 `lib/Service/TermijnTimerService::armHersteltermijn()`: one `preBreach`
  rung per reminder inside the budget (D-2).
- [x] 1.4 `lib/Listener/TermijnTimerFiredListener.php`: a `preBreach` rung on the
  hersteltermijn helper asks the chase service, which decides against the count.
- [x] 1.5 `lib/Service/Pause/PauseChaseService.php`: send through the one
  outbound route, record `chased` on the term and the timeline, count it, and
  escalate once after the budget (D-3).
- [x] 1.6 `lib/BackgroundJob/PauseChaseJob.php`: the second trigger, so chasing
  works on an instance with no timer engine. The count is what keeps the two
  triggers from both sending.
- [x] 1.7 `lib/Service/DeadlinePauseService::registerPauze()`: takes the reason
  key, refuses one the case type does not declare, and clears the lot on resume
  (D-4).
- [x] 2.1 The queue says who a case waits on, for how long, and how often it was
  chased.
  - There is no Suspend dialog in this app to put a reason picker in: the pause
    is registered through `POST /api/cases/{id}/information-request`, which
    already carried `pauseReason` from `aanvullingsverzoek-as-a-record`. The
    reason now resolves against the case type instead of being stored unread.
- [x] 2.2 `#CaseDetail` `case-terms`: the reason a clock is suspended for, and
  the reminders sent under it.
- [x] 3.1 `tests/e2e/pause-reason-with-chasing.spec.ts`, tagged and not run
  locally; the two time-dependent scenarios are excluded with their unit cover
  named.
