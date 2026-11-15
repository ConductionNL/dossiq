---
kind: code
depends_on: [termijnbewaking-op-engine-timers]
---

# Proposal: counting-mode-per-term

Competitor gap register, row Q8.16 "Is the counting mode a property of the
individual term" (`procest/_gaps/gap-register.md` in
ConductionNL/market-intelligence, 2026-09-13). Rated no, owner dossiq, size
S. Named in the register's first pick ("8.16 dossiq") beside the working
calendar rows.

## Why

Every term counts calendar days, and that is not a choice anyone made per
term. `lib/Service/TermijnService.php:109` runs `modify('+N days')`
unconditionally; `deadlineDefinition` in `register.d/60-termijnbewaking.json`
declares `standardDurationDays` and `standardDurationWeeks` and no mode;
`termijnbewaking-op-engine-timers` D-1 arms every timer with `unit:
calendarDays`. Awb beslistermijnen count calendar days, so the default is
right. Service norms, internal handling terms and KCC callbacks count
working days, and today they cannot.

The best competitor in the register: OpenProject 16,
`app/services/work_packages/shared/days.rb:38` selects AllDays or
WorkingDays per work package (`_round4/compare/proposed-rows.md`).

## What changes

- `deadlineDefinition.countingMode`: `calendarDays` (default) or
  `workingDays`.
- `TermijnTimerService::armBeslistermijn()` passes it as the timer's SLA
  unit instead of the constant.
- `TermijnService::createTermijnInstance()` computes `endDateCalculated`
  in the same mode, so the case data and the engine agree by construction,
  proven by a fixture pair.
- The termijn settings show the mode per definition.

## Ownership

dossiq builds the property and the two call sites: which mode a term counts
in is termijn configuration. It consumes OpenRegister's timer unit
(`flow-business-timers`, shipped) and the engine's working calendar for
`workingDays` (seeded `nl-national` today; administered under openregister's
`working-calendar-admin`, to be specified in openregister, row 8.12).

## ADRs

- Company ADR-022: the working-day arithmetic is the engine's
  `SlaCalculator`; dossiq's `WorkingDayCalculator` is not widened (its
  retirement is termijnbewaking phase 3).
- Company ADR-031: the mode is declared data.

## Capabilities

- Modified: `termijnbewaking-schemas`: a term declares its counting mode.

## Impact

`lib/Settings/register.d/60-termijnbewaking.json`;
`lib/Service/TermijnTimerService.php`; `lib/Service/TermijnService.php`;
the termijn settings tab; fixture pairs.
