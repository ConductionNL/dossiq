# Tasks: terms-on-the-engine-calendar

Tier: V1. Kind: code. Rows 8.11, 8.12, Q8.19 (dossiq halves), Q8.17
(history only). Statutory.

- [ ] 1.1 Legal confirmation: record here who confirmed the Awt art. 3
  recognised-holiday list against dossiq's case types, and the date. The
  default flip in 1.2 waits on this line.
- [ ] 1.2 `register.d/60-termijnbewaking.json` `deadlineDefinition.rollToWorkingDay`
  (default false until 1.1; then true for Algemene termijnenwet).
  - `@spec openspec/changes/terms-on-the-engine-calendar/specs/termijnbewaking-schemas/spec.md`
- [ ] 1.3 `TermijnTimerService::armBeslistermijn()`: the roll rule;
  `TermijnService::createTermijnInstance()`: the same roll; fixture pair
  (Koningsdag, Sunday, off).
- [ ] 1.4 `TermijnService`: dates built in the calendar's zone (D-4); unit
  test at the day boundary.
- [ ] 2.1 `tests/Unit/Architecture/NoLocalCalendarTest.php` with the
  allowlist (D-3).
- [ ] 2.2 Termijn settings: show the zone and the flag.
- [ ] 3.1 `#CaseDetail` `case-terms`: Moved dates section (D-5).
- [ ] 4.1 `openspec validate terms-on-the-engine-calendar --strict`.
