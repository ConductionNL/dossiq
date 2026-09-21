# Tasks: dwell-time-on-the-working-calendar

Tier: V1. Kind: code. Row Q10.15.

- [x] 1.1 The engine method for elapsed business hours, named.
  There was none, and the two that look like one both answer something else.
  `SlaCalculator::measure(..., 'hours', ...)` is the wall clock by
  construction; `measure(..., 'businessDays', ...)` counts fractions of a
  CALENDAR day on working days, so the Friday-16:00 interval reads 0.71 days
  and converts to 5.67 hours, counting Friday evening and Monday before dawn
  as work. The cause was that `WorkingCalendar` knew which days are worked
  and how many hours one holds, and never what time the office opens.
  Built in the engine, where the calendar lives:
  `SlaCalculator::elapsedBusinessHours()` over a `dayStartsAt` window,
  ConductionNL/openregister#3868. The D-2 interim is kept as the DEGRADED
  path, for an instance without OpenRegister, and it is named on screen
  rather than served silently.
- [x] 1.2 `lib/Service/ProcessMining/DwellTimeAnalyzer.php`: two numbers per
  interval; grouping by actor through `aggregateDwellStatsByActor()`.
  - fixture pair D-1 in `DwellOnTheWorkingCalendarTest`; degraded path in
    `WorkingClockTest`
  - `@spec openspec/changes/dwell-time-on-the-working-calendar/specs/doorlooptijd-dashboard/spec.md`
- [x] 1.3 `lib/Service/Doorlooptijd/CaseTypeThroughputCalculator.php`: the
  same two numbers, as `avgDays` beside `avgWorkingHours`.
- [x] 2.1 The two pages: column headers name the clock, and the By handler
  table is a widget on the process-mining page.
- [x] 3.1 `tests/e2e/dwell-time-clock.spec.ts`; `openspec validate
  dwell-time-on-the-working-calendar --strict`.

## Rescue verdict, 2026-09-18: SUPERSEDED, nothing to rescue

`feat/dwell-time-working-calendar` had no pull request of any state and looked
like unlanded work. It is not: **dossiq#2929, "dwell time counts working hours,
and the page says which clock", already landed this change on
`parity/round2`** under a different branch.

The evidence is the conflict itself. Both sides add one nullable-last parameter
to `DwellTimeAnalyzer`: parity's `?WorkingClock $clock` and the branch's
`?WorkingTimeMeasurer $workingTime` — two implementations of the same idea,
against the same spec file, in the same class. Parity's is the one that shipped,
with `WorkingClockTest` beside it and a `clock()` method that labels the columns
before a number is computed.

Merging the branch would have added a second measurer nothing calls, in a class
that already measures. The branch is left unmerged.
