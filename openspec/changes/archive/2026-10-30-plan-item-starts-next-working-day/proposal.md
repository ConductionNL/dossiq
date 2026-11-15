# Proposal: plan-item-starts-next-working-day

## Why

The "Iets inplannen" dialog on Uw wachtrij (DqUwWachtrij) opens with "Wanneer" filled in ("14-10-2026 10:00") on the
board. Live opens it empty, and because "Wanneer" is required the "Inplannen" button stays disabled until the reader
types a date. Ruben decided on 23 October 2026: the field starts at the next working day at 10:00, read from the
working-day calendar the organisation administers, and the reader can change it.

## What changes

- `GET /api/personal-queue/planned-items/defaults` answers `{ startsAt }`: the next working day after today at 10:00,
  through `WorkingDayCalculator` (the administered calendar, else the built-in list), as a `datetime-local` value.
- The dialog reads that answer as its record (`summary.source`, no summary row drawn) and starts "Wanneer" from it
  (`valuePath: "startsAt"`). The reader can change the value; the planned item is stored as before.
