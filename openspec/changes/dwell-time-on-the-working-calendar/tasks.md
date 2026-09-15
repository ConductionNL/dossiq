# Tasks: dwell-time-on-the-working-calendar

Tier: V1. Kind: code. Row Q10.15.

- [ ] 1.1 Name the engine method for elapsed business hours on openregister
  `development`; record it here. If none exists, note it for openregister
  and use `WorkingDayCalculator × 8` as the interim (D-2).
- [ ] 1.2 `lib/Service/ProcessMining/DwellTimeAnalyzer.php`: two numbers
  per interval; grouping by actor.
  - fixture pair D-1; degraded path
  - `@spec openspec/changes/dwell-time-on-the-working-calendar/specs/doorlooptijd-dashboard/spec.md`
- [ ] 1.3 `lib/Service/Doorlooptijd/CaseTypeThroughputCalculator.php`: the
  same two numbers.
- [ ] 2.1 The two pages: column headers name the clock; By assignee switch.
- [ ] 3.1 `tests/e2e/dwell-time-clock.spec.ts`; `openspec validate
  dwell-time-on-the-working-calendar --strict`.
