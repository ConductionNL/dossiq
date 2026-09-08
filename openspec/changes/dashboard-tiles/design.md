# Design: dashboard-tiles

## Context

The `Dashboard` page (`src/manifest.json`, `type: dashboard`) holds five `stat` tiles, two charts and six `object-table` widgets. `my-tasks` and `task-reminders` both read `caseTask` with `assignee: @me` and `isTerminalStatus: false`; they differ only in the due window. `overdue-cases` and `deadline-alerts` both read `case` with `isFinalStatus: false`; they differ only in the deadline window. Every table forwards `viewAllRoute` without a query (triage #4). The `stat` catalog is registered by a module the dashboard chunk never imports (triage #3). ADR-032 kind: **config**, with one thin-glue exception below.

## Goals / Non-Goals

**Goals:**
- One table for your tasks and one for the deadlines, each with days left per row.
- View all lands on the list already filtered the way the tile was.
- The KPI tiles render on the first page load.
- The New case form offers a usable case type list.

**Non-Goals:**
- Row actions with a toast and a confirm (nextcloud-vue, Tier B16).
- Recently used case types first (nextcloud-vue request, below).
- The Cases index chips and columns: `one-case-list` owns those.

## Decisions

### D1. Merge by filter, not by adding a tile

`my-work` is one `object-table` over `caseTask`, filter `assignee: "@me"`, `isTerminalStatus: false`, `extend: ["case", "calculations"]`, order `dueDate asc`, limit 10. Columns: `title`, `case.title` (the extend makes the title available; a `$ref` label column in nextcloud-vue is the durable form, triage #8), and `daysUntilDue` with the existing `conditionalPhrase` formatter. `deadlines` is one `object-table` over `case`, filter `isFinalStatus: false`, `deadline: {lte: "@today+3d"}`, order `deadline asc`, limit 10, with `daysUntilDeadline` rendered by the same formatter; the negative branch carries `cellClass: cn-cell--danger` so an overdue row reads red. Alternative considered: keep four tiles and dedupe rows client side. Rejected: the duplication is in the declaration, so the fix belongs there.

### D2. Row actions are lifecycle transitions

`caseTask` carries a valid `x-openregister-lifecycle` (Pick up = `available` to `active`, Complete = `active` to `completed`). The table declares `rowActions: [{"id": "pick-up", "type": "lifecycle", "transition": "activate"}, {"id": "complete", "type": "lifecycle", "transition": "complete"}]`. The 2.40.0 `object-table` vocabulary has no `rowActions` key; the task is marked blocked on nextcloud-vue and the row keeps its `rowRoute` to `TaskDetail`, where the buttons already render, as the interim.

### D3. View all repeats the filter

Each table's `viewAllRoute` becomes `{"name": "<Index>", "query": <the table's own filter>}`: `my-work` to `Tasks` with `assignee=@me&isTerminalStatus=false`, `deadlines` to `Cases` with `deadline[lte]=@today+3d&isFinalStatus=false`, `open-cases` to `Cases` with `isFinalStatus=false`, `stalled-cases` to `Cases` with its order. `CnIndexPage` merges the route query into its fetch and resolves `@today` (nc-vue `utils/routeFilters.js`), so nothing else changes. A nextcloud-vue nicety, defaulting `viewAllRoute.query` from `source.filter`, is filed but not waited on.

### D4. Register the widget catalog at boot (thin glue)

`src/main.js` imports `registerBuiltinDashboardWidgets` from `@conduction/nextcloud-vue` and calls it before `createApp`, the way `keepiq` and `hermiq` do. **Mixed-spec rationale (ADR-032):** two lines in one file, coupled to this change because the merged tiles are only visible once the page renders at all. Upstream fix: `CnDashboardPage` imports the catalog itself.

### D5. The case type field is filtered and ordered in the manifest

`headerActions[new-case].fieldOverrides.caseType` gains `filter: {"isDraft": false, "validUntil": {"gte": "@today"}}` and `order: {"title": "asc"}`. Types with no `validUntil` stay listed: the filter is applied as `validUntil >= @today OR validUntil IS NULL`, spelled the way the register's condition builder accepts null (`"validUntil": ["IS NULL", {"gte": "@today"}]` if the builder takes a list; the task verifies the spelling live). Recently used first needs a per-user usage read the form has no access to; that is a nextcloud-vue request (`recentFirst` on a reference field) and the task is marked blocked.

### Declarative-vs-imperative decision (ADR-031)

| behaviour | path | rationale |
|---|---|---|
| days left per row | declarative, existing `calculations` on `caseTask` and `case` | already declared, only rendered |
| overdue colouring | declarative, `cellClass` in the manifest column | presentation |
| row actions | declarative, `x-openregister-lifecycle` on `caseTask` | transitions already declared |
| widget catalog | code, two lines | bootstrap, no declarative analogue |

### Seed data

No schema changes. The demo caseload (`46-demo-cases-english.json`) already holds tasks due within a week and cases past their deadline, so both tables show rows on a fresh install.

## Risks / Trade-offs

- [The `@today+3d` token is not resolved by the index page] → the deadlines query falls back to `deadline[lte]` with a literal date computed by the widget; verify on a live instance before merging.
- [E2E selectors on the retired widget ids] → grep `tests/e2e` for `my-tasks`, `task-reminders`, `deadline-alerts`, `overdue-cases` before renaming (memory: retiring a surface leaves a stale e2e).
- [`rowActions` silently ignored by 2.40.0] → the row still opens `TaskDetail`; the blocked task names the need.

## Migration Plan

Manifest only. Deploy with the next release; no data migration, no rollback beyond reverting the manifest.
