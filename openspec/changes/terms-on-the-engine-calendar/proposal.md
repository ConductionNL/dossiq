---
kind: code
depends_on: [termijnbewaking-op-engine-timers]
---

# Proposal: terms-on-the-engine-calendar

Competitor gap register, rows 8.12 "One working-day calendar, administered
in one place" (slug `working-calendar-admin`, M), 8.11 "Term end date moved
off a weekend or public holiday" (slug `end-date-roll-on-the-calendar`, S)
and Q8.19 "Is the buyer's own time zone accepted where a calendar or a term
is configured" (slug `calendar-time-zone`, S), all owner openregister,
`procest/_gaps/gap-register.md` in ConductionNL/market-intelligence,
2026-09-13. The register's first pick to build first. This change is
dossiq's half of the three; the calendar, its admin surface, the roll rule
and the zone are openregister's, to be specified there under those slugs.
Q8.17 (`calendar-change-recomputes-timers`) has no dossiq half beyond
showing the history and is listed in the umbrella only.

## Why

Statutory correctness, not a feature. The register: "OpenProject's engine
measured dossiq's arithmetic: 30.4% of the terms dossiq stores land on a
day the Algemene termijnenwet says must move." `lib/Service/TermijnService.php:109`
computes `modify('+N days')` and stops; `grep -rinE "termijnenwet|\batw\b"
lib/ src/` returns zero hits; `WorkingDayCalculator`'s header says the Awt
roll is deliberately not implemented and "needs the recognised-holiday
list confirmed by someone qualified to read Awt art. 3". The re-read
(row Q8.19) finds no time zone anywhere in the term services, and the
term is computed on a zoneless date string.

The best competitors in the register: osTicket 1.18 HolidaysSchedule,
administered (`_round4/compare/promoted-rows-batch3.md`); GLPI 11
`Calendar::computeEndDate` (same file); Znuny 7.3 accepts Europe/Amsterdam
and Odoo `resource.calendar.tz` verified (`_round4/compare/pending-rows-batch6.md`).

## What changes

- `deadlineDefinition.rollToWorkingDay`: when true, the armed timer and
  `endDateCalculated` roll an end date that lands on a Saturday, Sunday
  or recognised holiday to the next ordinary day (Awt art. 1). Default
  true for `legalBasis` Algemene termijnenwet once the recognised-holiday
  list is confirmed (task 1.1); until then default false and visible.
- dossiq keeps no calendar. A structural test fails any `lib/` file that
  holds a holiday list except `WorkingDayCalculator`, whose entry names
  termijnbewaking phase 3 as its retirement.
- The zone is written down: term dates are calendar dates in the
  organisation calendar's zone; `TermijnService` builds them in that zone
  and the spec says so.
- `case-terms` shows the superseded-timer history when a date moved, so a
  handler sees why (Q8.17's dossiq half, consumed once openregister
  recomputes).

## Ownership

dossiq builds the flag, the two computations, the structural test and the
history panel. It consumes openregister `flow-business-timers` (shipped)
and, to be specified in openregister: `working-calendar-admin` (8.12),
`end-date-roll-on-the-calendar` (8.11), `calendar-time-zone` (Q8.19),
`calendar-change-recomputes-timers` (Q8.17).

## ADRs

- Company ADR-022: one calendar, the engine's; dossiq's copy retires.
- Company ADR-031: the roll is a declared flag.
- dossiq ADR-005: the term services stay dossiq's as the documented
  exception until the engine carries the statutory rules.

## Capabilities

- Modified: `termijnbewaking-schemas`: a term declares the Awt roll, its
  zone is stated, and dossiq holds no calendar.

## Impact

`register.d/60-termijnbewaking.json`; `lib/Service/TermijnService.php`,
`TermijnTimerService.php`; `tests/Unit/Architecture/NoLocalCalendarTest.php`;
`src/manifest.json` `#CaseDetail` `case-terms`; fixture pairs.
