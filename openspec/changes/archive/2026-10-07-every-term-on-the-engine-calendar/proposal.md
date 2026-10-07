---
kind: code
depends_on: [terms-on-the-engine-calendar]
---

# Proposal: every-term-on-the-engine-calendar

Competitor gap register, row Q8.23 "Is the calendar the deadline engine
reads the same calendar the organisation administers"
(`procest/_gaps/gap-register.md` in ConductionNL/market-intelligence, v3,
2026-09-14). Rated `partial`, owner dossiq, slug
`every-term-on-the-engine-calendar`, size M. Statutory. Batch 12 of round
4 proposed the row and measured us on it.

## Why

The row does not ask whether the calendar exists. It asks whether the
engine reads it, and that is a different and worse failure: an
administrator maintains a holiday list, believes it, and the dates come
out wrong anyway.

dossiq has the best calendar in the corpus. `lib/Service/WorkingDayCalculator.php`
administers the Dutch general holidays and computes Easter itself rather
than calling PHP's `easter_date()`, with a comment explaining why. Batch
12, verbatim: "Nothing in twenty-four driven systems comes close."

The calendar is not on the critical path by construction. Counted
read-only over `lib/` at `9c478d810`, **37 files do date arithmetic**
(`modify('+…')`, `new DateInterval`, `->add(`, `->sub(`,
`strtotime('+…')`) and **five reference `WorkingDayCalculator`**
(`BackgroundJob/DsoDeadlineJob.php`, `Service/ComplaintService.php`,
`Service/DsoCaseService.php`, `Service/Kcc/SlaCalculator.php` and the
calculator itself). **Thirty-two do not**, and three of those are term
paths by name:

| file | line | what it does |
|---|---|---|
| `Service/Beschikking/BezwaarTermijnScheduler.php` | 76 | the bezwaar term as `->add(new DateInterval('P6W'))` from the bekendmaking date, and the reminder as `->sub(new DateInterval('P1W'))` |
| `Service/NoticeOfDefaultService.php` | 171 | the ingebrekestelling grace period as `->modify('+N days')` |
| `Service/DeadlinePauseService.php` | 93, 172 | a suspension credited back, and an unused remainder taken off, both `->modify('±N days')` |

Six weeks from bekendmaking is the right term. What the Algemene
termijnenwet then requires is that a term ending on a Saturday, Sunday or
general holiday moves to the next working day. The calculator's own
header says it: the Awt roll is "NOT IMPLEMENTED HERE, deliberately …
and no caller does either. That is a real gap".

The corpus cannot help us here. Huly 0.7.426 has a real `PublicHoliday`
object with a department and a screen, and every reader of it is an HR
view; its one working-day function hard-codes Saturday and Sunday and
consults nothing. Tuleap CE 17.5 has a weekday mask and no holiday object
at all. No competitor scores `yes`, which makes this a row we lose to
ourselves rather than to a vendor.

## What changes

- **An audit, file by file, of the 37.** Each gets a verdict: statutory
  term path, business date that must roll, or neither (a report window, a
  throughput trend, seed data, money arithmetic). The verdict and its
  reason are written down, so the next reader does not repeat the grep.
  The count is an upper bound until then, not a defect list.
- **The three named paths compute through the calendar.** The bezwaar
  term, the ingebrekestelling grace and the pause credit take the same
  roll `terms-on-the-engine-calendar` declares for `TermijnService`.
- **A structural test with a reason-bearing allowlist.** A file that
  computes a statutory date without reaching the calendar fails the
  build, unless the allowlist names it and says why. That turns the audit
  from a document into a fact the build keeps true.
- **The pause arithmetic keeps its owner.** `termijnbewaking-op-engine-timers`
  REQ-TOT-002 freezes `endDateCurrent` arithmetic as case data. This
  change does not move it onto the engine; it makes the day it lands on a
  working day.

## Ownership

dossiq builds all of it. The calendar, the roll rule, the admin surface
and the zone are openregister's, as the register says, and dossiq
consumes them through `terms-on-the-engine-calendar`. What is dossiq's is
which of its own services call them, which is code hygiene, exactly as
row 8.22 was.

Consumed: openregister `working-calendar-admin` (8.12),
`end-date-roll-on-the-calendar` (8.11), both to be built there; dossiq
`terms-on-the-engine-calendar` (open), which declares
`deadlineDefinition.rollToWorkingDay` and applies it to `TermijnService`
and `TermijnTimerService`.

## Why this is a change of its own

Neither open change carries the row, and both were read before this one
was written.

- `terms-on-the-engine-calendar` owns the roll rule and applies it to two
  call sites, `TermijnTimerService::armBeslistermijn()` and
  `TermijnService::createTermijnInstance()`. It names
  `TermijnService.php:109` as the motivating defect and never generalises
  to the rest of `lib/`. Its `NoLocalCalendarTest` forbids a second
  holiday list; it does not find a term that skips the first one.
- `termijnbewaking-op-engine-timers` names all three files, but to move
  their clock onto engine timers. Its task 1.4 is ticked and its
  REQ-TOT-002 freezes `DeadlinePauseService`'s arithmetic as case data on
  purpose, and its proposal lists `NoticeOfDefaultService` under what
  stays unchanged. Its task 4.2 sweeps `lib/` for `->diff(` outside the
  calculation classes, which is a different grep for a different reason.

Extending either would edit another lane's open change to do a third
thing, which is what the register's own `task-search-fields` decision
refused. This change depends on the first and does not touch the second.

## ADRs

- Company ADR-022: one calendar, the engine's. dossiq reaches it, it does
  not copy it.
- Company ADR-031: the roll is a declared flag on the definition, not a
  branch in a service.
- dossiq ADR-005: the term services stay dossiq's as the documented
  exception until the engine carries the statutory rules, so the audit is
  dossiq's to run.

## Capabilities

- Modified: `termijnbewaking-schemas`: every statutory term computes on
  the engine calendar, the three named paths included, and the build
  keeps it true.

## Impact

`lib/Service/Beschikking/BezwaarTermijnScheduler.php`,
`lib/Service/NoticeOfDefaultService.php`,
`lib/Service/DeadlinePauseService.php`;
`tests/Unit/Architecture/EveryTermOnTheCalendarTest.php` and the
allowlist beside it; `tests/Unit/Service/Beschikking/`,
`tests/Unit/Service/`; `docs/research/date-arithmetic-audit-2026-09-14.md`.

## Out of scope

- The roll rule itself and the zone. `terms-on-the-engine-calendar` owns
  both and this change consumes them.
- Moving any clock onto an engine timer. That is
  `termijnbewaking-op-engine-timers` phase by phase.
- The write paths that disagree about the same field. That is row 8.22
  and `one-date-write-path`, which this change neither repeats nor waits
  on.
- The twenty-nine files the audit clears. A report window and a
  throughput trend do not need a working-day roll, and saying so once is
  the point of writing the verdict down.
