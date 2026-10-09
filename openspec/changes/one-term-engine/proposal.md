---
kind: code
depends_on: []
---

# Proposal: one-term-engine

Ruben's decision of 2026-10-09, made reviewing the board `dossiq/DqMijnWerk`:
one term engine. A case's deadline is computed in one place, and every list,
card, count and page reads that one answer.

## Why

Read on `origin/development` at d4e0c0f5d, a case deadline is computed in
four places that disagree:

- `case.deadline` is an OpenRegister calculation,
  `dateAdd(startDate, @ref.caseType.processingDeadline)`
  (`lib/Settings/dossiq_register.json`, `x-openregister-calculations.deadline`).
  It counts calendar days, never rolls off a weekend or holiday, and ignores
  pauses and extensions. Every list, card, week strip and count reads it.
- The statutory `TermijnInstance` that `DeadlineCaseCreatedListener` creates
  counts from the moment the case was created, not from receipt, rolls with the
  Algemene termijnenwet, and is the only thing a pause or an extension moves.
  An extension is stored unrolled.
- `CaseTermsService::endAfter()` counts calendar days for the planned end and
  the internal target, while the schema says those are working days.
- `WOODeadlineService` keeps its own clock (28, 14 and 7 days as constants),
  counts from `receiptDate`, and writes three keys the case schema does not
  declare (`expectedResolution`, `deadlineVerlengd`, `verdagingReden`).
- `WorkQueueService::businessDaysBetween()` counts Monday to Friday and ignores
  the administered calendar (contradicts REQ-TERM-026), and the urgency score
  reads only running terms, not paused or extended ones (contradicts the
  my-work spec).
- Five front-end helpers each compute days left; two of them, and the simple
  structure's week strip, list column and board, call a term late on its last
  day, which REQ-TERM-DAY-001 says it is not.

So a list can say one date while the case page says another, and the citizen
can be told a third.

## What changes

1. **The running statutory term instance is the single source.** Creating,
   pausing, resuming, extending and rolling it writes `case.deadline` back. A
   pre-persist listener keeps that value on every later save of the case, so
   the OpenRegister calculation can no longer overwrite it. The calculation
   stays only as the fallback for a case that has no statutory term instance
   (a case type with no term definition); REQ-TERM-001 is amended to say so.
2. **The term starts at receipt.** The statutory instance starts at the case's
   `termStartsAt` (the first working moment on or after `receivedAt`, stamped
   by `intake-says-when-the-term-starts`), not at the moment the case row was
   created. The planned end and the internal target start there too.
3. **Woo uses the generic engine** through the seeded definition
   `td-woo-verzoek`. `WOODeadlineService` loses its own clock and the three
   undeclared keys; its extension goes through `DeadlineExtensionService` on
   the case's statutory instance. Sections 1 to 3 of
   `woo-term-is-computed-and-reported-right` are folded in here; its report
   section stays in that change.
4. **Working days go through the administered calendar everywhere.** The
   planned end and the internal target count working days through
   `WorkingDayRoll`; the work queue counts working days through it; an
   extension's end date is rolled before it is stored.
5. **A term is not late on its last day, anywhere**: server status, timer
   breach, list column, board card, week strip and My Work card.
6. **One front-end helper** answers days left and overdue for every surface,
   replacing the five.
7. **The work-queue score includes paused and extended terms.**
8. **Existing cases are repaired once**: a repair step writes each case's
   `deadline` from its statutory instance and re-arms running beslistermijn
   timers so they breach after the last day.

## Capabilities

- Modified: `termijn-binding` (REQ-TERM-001, REQ-TERM-060; new REQ-OTE-01,
  REQ-OTE-02, REQ-OTE-05, REQ-OTE-08).
- Modified: `termijn-pause-extension` (new REQ-OTE-03).
- Modified: `termijnbewaking-schemas` (new REQ-OTE-04).
- Modified: `woo-case-type` ("WOO deadline tracking and extension").
- Modified: `my-work` ("Card Display [MVP]"; new REQ-OTE-06, REQ-OTE-07).
- Modified: `my-work-landing` ("My Work carries three widgets").

## Relation to open changes

- `intake-says-when-the-term-starts`: its stamp is built; this change makes the
  term actually count from it (its own text: "the first working moment the term
  counts from").
- `woo-term-is-computed-and-reported-right`: tasks 1 to 3 (rolled deadline,
  rolled API end dates, Woo extension through the engine) move here. Task 4
  (the quarterly report) stays there.
- `termijnbewaking-op-engine-timers`: unchanged; this change only moves the
  timer's anchor to the start of the start day.
- Another lane changes the work-queue urgency tiers in `WorkQueueService` at
  the same time. This change touches only `businessDaysBetween()` and the
  instance query there.

## Out of scope

- The quarterly report (`woo-term-is-computed-and-reported-right` task 4).
- The portal's own confirmation screen (portaliq).
- Editing the boards. DqMijnWerk draws the case whose term ends today with the
  late border on its week strip, and DqTermijnen's "Verlopen" tile says
  "Beslis vandaag of verdaag"; both are noted for the design-system owner.
