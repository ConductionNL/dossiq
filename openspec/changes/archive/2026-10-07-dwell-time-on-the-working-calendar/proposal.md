---
kind: code
depends_on: [termijnbewaking-op-engine-timers]
---

# Proposal: dwell-time-on-the-working-calendar

Competitor gap register, row Q10.15 "Elapsed working time per case,
measured on the calendar, reported per stage or assignee"
(`procest/_gaps/gap-register.md` in ConductionNL/market-intelligence,
2026-09-13). Rated partial, owner dossiq, size S.

## Why

The process mining page reports how long a case sat in each phase, on the
wall clock. `lib/Service/ProcessMining/DwellTimeAnalyzer.php:160` computes
`hours = (exitedAt - enteredAt) / 3600`. A phase entered Friday at 16:00
and left Monday at 09:00 reads 65 hours; the organisation worked one of
them. The page does not say which clock it uses, so a manager compares
teams on a number that rewards whoever gets the Friday afternoon cases.

The best competitor in the register: Odoo 19.0, `working_hours_open` and
friends on every task, `project/models/project_task.py:255-258`, verified
live (`_round4/compare/proposed-rows-batch6.md`).

## What changes

- `DwellTimeAnalyzer` counts working hours on the engine's organisation
  calendar and reports both: working hours as the headline, wall-clock
  hours as a second column.
- The Process mining page and the Processing time page say which clock a
  number is on, in the column header.
- Per assignee as well as per phase, where the status record names one.

## Ownership

dossiq builds the two readers and the labels: which clock a dossiq report
counts on is dossiq's. It consumes the engine's `WorkingCalendarService`
and `SlaCalculator` (openregister `flow-business-timers`, shipped; the
calendar administered under `working-calendar-admin`, to be specified in
openregister, row 8.12). `WorkingDayCalculator` is not widened; its
retirement is termijnbewaking phase 3.

## ADRs

- Company ADR-022: one calendar, the engine's.
- Company ADR-112: reports are one page; the clock is a column, not a
  second report.

## Capabilities

- Modified: `doorlooptijd-dashboard`: dwell and processing time are
  reported in working hours, and the page says so.

## Impact

`lib/Service/ProcessMining/DwellTimeAnalyzer.php`;
`lib/Service/Doorlooptijd/CaseTypeThroughputCalculator.php`; the two
report pages' column labels; fixture pairs; one e2e spec.
