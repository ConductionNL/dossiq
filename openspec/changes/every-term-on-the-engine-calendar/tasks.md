# Tasks: every-term-on-the-engine-calendar

Tier: V1. Kind: code. Row Q8.23. Statutory. Depends on
`terms-on-the-engine-calendar` for the roll rule; task 1.1 depends on
nothing and can run first.

- [x] 1.1 The audit: `docs/research/date-arithmetic-audit-2026-09-14.md`,
  one row per file for all 37 that do date arithmetic under `lib/`, with
  a verdict (statutory term, business date that must roll, neither) and
  the reason in one clause (D-1, D-4). The five that already reach
  `WorkingDayCalculator` are rows too, so the file is the whole set.
  - `@spec openspec/changes/every-term-on-the-engine-calendar/specs/termijnbewaking-schemas/spec.md`
- [x] 1.2 `lib/Service/Beschikking/BezwaarTermijnScheduler.php:76`: the
  bezwaar term and its reminder roll through the calendar (D-2).
  - `tests/Unit/Service/Beschikking/BezwaarTermijnSchedulerTest.php`, fixture pair (Koningsdag, Sunday, off)
- [x] 1.3 `lib/Service/NoticeOfDefaultService.php:171`: the
  ingebrekestelling grace rolls; the validity rules are untouched (D-2).
  - `tests/Unit/Service/NoticeOfDefaultServiceTest.php`
- [x] 1.4 `lib/Service/DeadlinePauseService.php:93,172`: the credited
  suspension and the unused remainder land on a working day; the
  arithmetic stays case data per REQ-TOT-002 (D-2).
  - `tests/Unit/Service/DeadlinePauseServiceTest.php`
- [ ] 2.1 `tests/Unit/Architecture/EveryTermOnTheCalendarTest.php`: a
  file the audit marks statutory that computes a date without reaching
  the calendar fails, naming the file and the line; the allowlist entry
  carries a reason (D-3).
- [ ] 2.2 Every file the audit marks statutory and does not fix in 1.2 to
  1.4 gets an allowlist entry naming the change that will take it, or is
  fixed here. An entry without a named owner is not an entry.
- [ ] 3.1 Re-run the count in the audit's header after 1.2 to 1.4 and
  record the new one beside the old, so the row can be re-rated from the
  file rather than from a grep.
- [ ] 3.2 `openspec validate every-term-on-the-engine-calendar --strict`.
