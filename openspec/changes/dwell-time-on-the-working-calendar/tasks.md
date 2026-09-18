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
