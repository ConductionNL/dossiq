---
kind: config
depends_on: []
---

# Proposal: dashboard-tiles

## Why

The landing page shows your work four times. The tiles `my-tasks` and `task-reminders` share 7 of their 10 rows, and `overdue-cases` and `deadline-alerts` share 7 of 9. No row tells you how many days you have left, and no row lets you act. Two defects sit underneath: on a fresh load five `stat` tiles say "Widget not available" (triage #3), and "View all" on every table drops the tile's filter and opens the full list (triage #4). The New case form lists twenty unsorted case types, drafts and expired types included (baseline journey J2).

Every competitor in round 2 lands on one personal work list with a deadline per row:

- opencase: `concurrentie-analyse/procest/opencase/round2/home-anatomy.md` (section 3, Suggested work: steps and reminders with a deadline per row)
- gzac: `concurrentie-analyse/procest/valtimo/round2/dashboard-anatomy.md` (Aan mij toegewezen tile, clickable)
- zaaksysteem: `concurrentie-analyse/procest/xxllnc-zaken/round2/pages/LegacyDashboard.md` (Mijn openstaande zaken with a Dagen column and per-row Zaakacties)
- case type list on create: `concurrentie-analyse/procest/xxllnc-zaken/round2/pages/Zaak-aanmaken-dialog.md` (Zaaktype autocomplete with favourites), `concurrentie-analyse/procest/opencase/round2/journeys.md` (J8)

Findings A09 and A12, defects D02 and D03 in `_round2/compare/findings.md`. Placement rung 2 (a widget on an existing page) in `_round2/compare/placement.md`.

## What Changes

- Page `Dashboard`: the `object-table` widgets `my-tasks` and `task-reminders` merge into one table `my-work` with the columns title, case and days left, and the row actions Pick up and Complete.
- Page `Dashboard`: the `object-table` widgets `overdue-cases` and `deadline-alerts` merge into one table `deadlines`, ordered by deadline, days left on every row, red when past due.
- Every remaining table carries a `viewAllRoute.query` equal to its own filter, so View all keeps what the tile showed (D03).
- `src/main.js` calls `registerBuiltinDashboardWidgets()` before the app mounts, so the five `stat` tiles render on a fresh load (D02). Two lines, the thin-glue exception of ADR-032.
- Header action `new-case`: the `caseType` field orders by title and hides drafts and expired types. Recently used types first waits on nextcloud-vue.

## Capabilities

### New Capabilities

None.

### Modified Capabilities

- `dashboard`: one personal work table with days left and row actions; View all keeps the tile's filter; stat tiles render on a fresh load; the case type list on New case is sorted and filtered.
- `signalering-widgets`: the deadline alerts and overdue tables become one deadlines table.

## Impact

- `src/manifest.json`, page `Dashboard`: four widgets become two, five `viewAllRoute` entries gain a `query`, one `headerActions` field gains a filter and an order.
- `src/main.js`: one import and one call.
- E2E: `tests/e2e/dashboard-tiles.spec.ts` (new). The existing `tests/e2e/pages.spec.ts` dashboard assertions keep passing; widget ids `my-tasks`, `task-reminders`, `deadline-alerts` and `overdue-cases` disappear, so any selector on them moves to `my-work` and `deadlines`.
- No schema change. No backend change. Pipelinq is not affected.
