# Tasks: dashboard-tiles

## 1. Defects first (triage #3, #4)

- [x] 1.1 `src/main.js`: import `registerBuiltinDashboardWidgets` from `@conduction/nextcloud-vue` and call it before `createApp`; verify by loading `/apps/dossiq/` in a fresh context and seeing five numbers instead of "Widget not available". [MVP] — landed on `development` ahead of this branch (#1871). `src/main.js` calls it before `createApp`, `tests/vitest/dashboardWidgetCatalogBootstrap.spec.js` pins it, and `tests/e2e/pages.spec.ts` asserts five numbers on a hard load. Verified: `npx vitest run tests/vitest/dashboardWidgetCatalogBootstrap.spec.js` exit 0.
- [x] 1.2 `src/manifest.json` page `Dashboard`: add `viewAllRoute.query` to `open-cases` and `stalled-cases` equal to each table's `source.filter`; verify View all opens `/cases` with the query in the URL. [MVP] — landed on `development` ahead of this branch (#1871). `tests/vitest/dashboardViewAllRoutes.spec.js` flattens every table's `source.filter` and requires each pair in its `viewAllRoute.query`, so the two tables this change adds are held to the same rule. Verified: `npx vitest run tests/vitest/dashboardViewAllRoutes.spec.js` exit 0.

## 2. Merge the tiles

- [x] 2.1 Replace `my-tasks` and `task-reminders` with one `object-table` `my-work` (schema `caseTask`, filter `assignee: @me`, `isTerminalStatus: false`, `extend: ["case", "calculations"]`, order `dueDate asc`, limit 10, columns title, case title, `daysUntilDue`, `viewAllRoute` to `Tasks` with the same filter); verify each open task appears once. [MVP] — done. `tests/vitest/dashboardWorkTables.spec.js` refuses any two tables over one schema whose filters nest and whose sort key matches, which is exactly the shape `my-tasks` and `task-reminders` had; the predicate is proved able to say yes on that pair before the sweep runs, so the loop cannot pass by finding nothing.
- [x] 2.2 Replace `overdue-cases` and `deadline-alerts` with one `object-table` `deadlines` (schema `case`, filter `isFinalStatus: false`, `deadline lte @today+3d`, order `deadline asc`, limit 10, `daysUntilDeadline` with `cn-cell--danger` on the negative branch, `viewAllRoute` to `Cases` with the same filter); verify the overdue row renders in `--color-error-text`. [MVP] — done, with one deviation recorded in design D1: a column's `cellClass` is a single static string and has no per-branch form, so the red comes from the table's declarative `rowClass` rules (`daysUntilDeadline lt 0` adds `cn-row--danger`). `src/assets/app.css` paints `.cn-data-table tr.cn-row--danger td` with `--color-error-text`, two class levels so it beats nextcloud-vue's own `td.cn-cell--muted` rule without `!important`.
- [x] 2.3 Update the `layout` of page `Dashboard` so the two new ids take the four old slots and nothing renders twice; verify with `npm run check:manifest` or the manifest parity test the repo carries. [MVP] — done. Row `y=6` is My work, Deadlines, Open cases; Stalled cases moves to `x=0, y=10`. `check:manifest` exit 0, and the new spec walks the grid cell by cell for overlaps, checks `gridX + gridWidth <= 12`, and asserts every widget is placed exactly once and every cell names a widget that exists.
- [x] 2.4 [blocked: nextcloud-vue rowActions on object-table] Declare `rowActions` Pick up and Complete on `my-work` as `caseTask` lifecycle transitions; interim: the row keeps `rowRoute: TaskDetail`. [V1] — still blocked, interim shipped. Read against the installed 2.41.0: `CnWidgetObjectTable` declares no `rowActions` prop, so the key would be dropped silently. `my-work` keeps `rowRoute: TaskDetail`, and the spec asserts `rowActions` is absent rather than present-and-inert.
- [ ] 2.5 Header action `new-case`: add `fieldOverrides.caseType.filter` (`isDraft: false`, `validUntil` on or after today or absent) and `order: {title: asc}`; verify the spelling of the null-or-future condition against a live instance and record it in design D5. [MVP]
- [ ] 2.6 [blocked: nextcloud-vue recentFirst on a reference field] Recently used case types first; no interim. [V1]

## 3. Tests

- [ ] 3.1 Add `tests/e2e/dashboard-tiles.spec.ts`: fresh-context KPI render, single My work table with days left, single Deadlines table with an overdue row in red and no closed case, View all carrying the filter, New case type list sorted without drafts; verify it passes locally against the demo caseload. [MVP]
- [ ] 3.2 Grep `tests/e2e` for `my-tasks`, `task-reminders`, `deadline-alerts`, `overdue-cases` and move every selector to the new ids; verify `pages.spec.ts` and `smoke.spec.ts` still pass. [MVP]
- [ ] 3.3 Record `dashboard-tiles` in `openspec/specs/dashboard/spec.md` and `openspec/specs/signalering-widgets/spec.md` under OpenSpec changes and set both to in-progress. [MVP]

## Acceptance criteria

- Five stat tiles show a value on a fresh load.
- Each open task and each case within the deadline window appears exactly once on the Dashboard.
- Every View all link carries its tile's filter as a route query.
- The New case form lists no draft or expired case type.

## Quality reminders

- Manifest edits are text inserts; read the diff, not the parity check, as evidence.
- i18n: new labels My work and Deadlines in `l10n/en.json` and `l10n/nl.json`.
