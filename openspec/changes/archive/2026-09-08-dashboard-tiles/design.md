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

`my-work` is one `object-table` over `caseTask`, filter `assignee: "@me"`, `isTerminalStatus: false`, `extend: ["case", "calculations"]`, order `dueDate asc`, limit 10. Columns: `title`, `case.title` (the extend makes the title available; a `$ref` label column in nextcloud-vue is the durable form, triage #8), and `daysUntilDue` with the existing `conditionalPhrase` formatter. `deadlines` is one `object-table` over `case`, filter `isFinalStatus: false`, `deadline: {lte: "@today+3d"}`, order `deadline asc`, limit 10, with `daysUntilDeadline` rendered by the same formatter. **Amended in build:** the red comes from the table's `rowClass` rules, not a `cellClass` branch. A column's `cellClass` is one static string with no per-value form, and the spec asks for the ROW to read in the error colour anyway, so `deadlines` declares `rowClass: [{"when": {"field": "daysUntilDeadline", "op": "lt", "value": 0}, "class": "cn-row--danger"}]` and `src/assets/app.css` paints it with `--color-error-text`. `my-work` carries the same rule on `daysUntilDue`. Alternative considered: keep four tiles and dedupe rows client side. Rejected: the duplication is in the declaration, so the fix belongs there.

### D2. Row actions are lifecycle transitions

`caseTask` carries a valid `x-openregister-lifecycle` (Pick up = `available` to `active`, Complete = `active` to `completed`). The table declares `rowActions: [{"id": "pick-up", "type": "lifecycle", "transition": "activate"}, {"id": "complete", "type": "lifecycle", "transition": "complete"}]`. The 2.40.0 `object-table` vocabulary has no `rowActions` key; the task is marked blocked on nextcloud-vue and the row keeps its `rowRoute` to `TaskDetail`, where the buttons already render, as the interim.

### D3. View all repeats the filter

Each table's `viewAllRoute` becomes `{"name": "<Index>", "query": <the table's own filter>}`: `my-work` to `Tasks` with `assignee=@me&isTerminalStatus=false`, `deadlines` to `Cases` with `deadline[lte]=@today+3d&isFinalStatus=false`, `open-cases` to `Cases` with `isFinalStatus=false`, `stalled-cases` to `Cases` with its order. `CnIndexPage` merges the route query into its fetch and resolves `@today` (nc-vue `utils/routeFilters.js`), so nothing else changes. A nextcloud-vue nicety, defaulting `viewAllRoute.query` from `source.filter`, is filed but not waited on.

### D4. Register the widget catalog at boot (thin glue)

`src/main.js` imports `registerBuiltinDashboardWidgets` from `@conduction/nextcloud-vue` and calls it before `createApp`, the way `keepiq` and `hermiq` do. **Mixed-spec rationale (ADR-032):** two lines in one file, coupled to this change because the merged tiles are only visible once the page renders at all. Upstream fix: `CnDashboardPage` imports the catalog itself.

### D5. The case type field is scoped on the schema property, not in the manifest

**Amended after task 2.5 verified the spelling.** The sketch above put `filter` and `order` on `headerActions[new-case].fieldOverrides.caseType`. Read against the installed nextcloud-vue, that spelling does nothing:

- `fieldsFromSchema` copies a field override onto the field object with `Object.assign`, so any key at all is accepted.
- `CnFormDialog.fetchReferenceOptions` then builds the picker's request from `{ _limit: 100 }` plus the SCHEMA property's `x-relation-filter`. It never reads the field, and it never sends `_order`.
- The manifest schema types `fieldOverrides` as `additionalProperties: true`, so a `filter` there validates, ships, and is ignored with nothing logged.

So the scoping is declared where the picker reads it: `x-relation-filter` on `case.caseType` in `lib/Settings/dossiq_register.json`, the spelling `case.status` already uses to scope its own picker. A register property is inert until `occ upgrade` runs the import, and the import fast-skips a schema whose `version` did not move, so the `case` schema goes 1.14.0 to 1.15.0 with it.

**What that buys, and what it does not.**

`isDraft: false` is expressible and exact, so drafts are gone.

Two halves of the requirement are not expressible and are recorded as blocked rather than shipped as an inert key:

1. **Expired types.** `validUntil >= @today OR validUntil IS NULL` needs an OR. OpenRegister's filter grammar ANDs every operator on a property (`MagicSearchHandler`, `COMPARISON_OPERATORS = ['gte','lte','gt','lt','in','notIn','ne','isnull']`, each added with `andWhere`) and offers no top-level OR key. Spelling it as a list (`["IS NULL", {"gte": "@today"}]`) does not survive either: `fetchReferenceOptions` walks an object value with `Object.entries`, so an array arrives on the wire as `validUntil[0]=IS NULL`. Plain `validUntil[gte]=@today` IS expressible, and it drops every type with an open-ended validity, which is the larger group. Blocked on OpenRegister.
2. **Alphabetical order.** `fetchReferenceOptions` sends no `_order` at all, and no schema-level default sort exists. Blocked on nextcloud-vue, alongside `recentFirst`.

A custom picker component is not the way round this: dossiq already learned (see the `InitiatorPicker` note in `src/registry.js`) that nextcloud-vue validates a form-field widget entry and does not mount it, so a `CaseTypePicker` would be the same silent no-op one layer down.

Recently used first needs a per-user usage read the form has no access to; that is a nextcloud-vue request (`recentFirst` on a reference field) and the task stays blocked.

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
