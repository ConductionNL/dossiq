# Tasks: Dashboard

> **Architecture-adaptation note (hydra build 2026-06-03).** Since this change
> was authored, procest migrated to the **manifest-v2 declarative shell**
> (`@conduction/nextcloud-vue` CnAppRoot). There is **no `src/router/router.js`,
> no `src/views/Dashboard.vue`, and no `src/components/MainMenu.vue`** — pages
> are declared in `src/manifest.json`, custom full-page views are resolved
> through `src/registry.js`, and the left menu is the manifest `menu[]` array.
> Navigation uses the CnAppRoot `$router` shim (`$router.push({ name: '<pageId>' })`).
> Tasks below are implemented against that real shell, not the assumed
> vue-router layout. In addition, prior changes
> (`retrofit-2026-05-24-dashboard`, `doorlooptijd-dashboard`) already shipped
> most of the V1 surface — those tasks are marked done with a reference, and
> only genuinely-missing pieces were newly built.

## Deduplication Check

- [x] **DED-01**: Confirm no overlap with existing implementations — search `openspec/specs/` for existing dashboard, signalering, and analytics specs; verify `src/views/dashboard/`, `src/views/analytics/`, and `lib/AppInfo/Application.php` do not already contain the components and registrations listed below. **Findings**: `openspec/specs/dashboard/spec.md` (status: implemented) covers MVP only; V1 requirements are unimplemented. `openspec/specs/signalering-widgets/spec.md` and `openspec/specs/doorlooptijd-dashboard/spec.md` have no corresponding implementation files. `Application.php` is missing `registerDashboardWidget` calls for the three existing widget classes. No overlap with ObjectService, RegisterService, SchemaService, or ConfigurationService.

---

## Implementation Tasks

### Application.php Widget Registration (Fix)

- [x] **T01** *(already shipped — verified on HEAD)*: Fix `lib/AppInfo/Application.php` — Add `$context->registerDashboardWidget(CasesOverviewWidget::class)`, `$context->registerDashboardWidget(MyTasksWidget::class)`, `$context->registerDashboardWidget(OverdueCasesWidget::class)` in the `register(IRegistrationContext $context)` method. Ensure all three widget class files are imported at the top of Application.php. Verify correct namespace for each widget class.
  - @spec openspec/changes/dashboard/tasks.md#T01

- [x] **T02** *(already shipped — `.dashboard.page` confirmed on HEAD for all 7 widgets)*: Fix `lib/Dashboard/CasesOverviewWidget.php` — Change the route reference from `.dashboard.index` to `.dashboard.page` in the widget's URL generation. Run `curl` on the rendered widget URL to verify the route resolves.
  - @spec openspec/changes/dashboard/tasks.md#T02

### Signalering Helpers

- [x] **T03** *(done — placed in existing `src/utils/dashboardHelpers.js`, not a new module, per the established convention where `getDeadlineAlerts`/`getTaskDueReminders`/`getStalledCases` already live; `getWooCases` + `aggregateByType` newly added there)*: Create `src/utils/signaleringHelpers.js` — Export four pure functions:
  - `getDeadlineAlerts(openCases, caseTypes, warningDays = 3)`: filter cases where `deadline` is within `warningDays` days of today OR in the past. For each match, compute `daysRemaining` (negative if overdue) and `severity` ('overdue' | 'critical' | 'warning'). Sort: overdue first (most overdue), then ascending `daysRemaining`. Include: `{ id, identifier, title, caseTypeName, daysRemaining, isOverdue, severity }`.
  - `getTaskReminders(tasks, warningDays = 3)`: filter tasks where `dueDate` is within `warningDays` days or past. Exclude tasks with no `dueDate`. Compute `daysRemaining` and `isOverdue`. Sort: overdue first, then ascending `dueDate`. Include: `{ id, title, caseId, caseIdentifier, daysRemaining, isOverdue, priority }`.
  - `getStalledCases(openCases, caseTypes, stalledDays = 7)`: filter cases where `(today - updatedAt) >= stalledDays`. Compute `daysSinceUpdate`. Sort: most stalled first. Include: `{ id, identifier, title, caseTypeName, daysSinceUpdate, assignee }`.
  - `getWooCases(openCases, caseTypes)`: filter cases whose resolved `caseType.title` (case-insensitive) includes 'woo'. For each, compute `daysRemaining` from `deadline`, and `severity` ('overdue' | 'critical' ≤7d | 'warning' ≤14d | 'ok' >14d). Sort: overdue first, then ascending `daysRemaining`. Include: `{ id, identifier, title, deadline, daysRemaining, severity }`.
  - Use `new Date().toISOString().slice(0, 10)` for today. Case name resolution from `caseTypes` array by matching UUID.
  - @spec openspec/changes/dashboard/tasks.md#T03

### Cases by Type Chart

- [x] **T04** *(already shipped as `src/views/dashboard/CasesByType.vue` by retrofit-2026-05-24-dashboard — CSS bar chart, click-to-filter, empty/loading/error states all present)*: Create `src/views/dashboard/CaseTypeChart.vue` — Horizontal bar chart component using pure CSS (same pattern as `StatusChart.vue`). Props: `typeData: Array<{ name, count }>`, `loading: Boolean`, `error: String|null`. Title: "Cases by Type". Each bar: `div` with `width: (count / maxCount * 100)%` (minimum 20px), type name left-aligned, count right-aligned. Colors cycle from a 6-color CSS variable palette. Click on bar emits `@click-bar(name)`. Empty state: "No open cases". Loading: 4 skeleton bars. Error state: inline message with retry button. Add `<!-- SPDX-License-Identifier: EUPL-1.2 -->` as first line.
  - @spec openspec/changes/dashboard/tasks.md#T04

### Woo Deadline Panel

- [x] **T05** *(done — new file; severity by colour AND text label; surfaced on the Analytics/Doorlooptijd page which already loads all cases + types)*: Create `src/views/dashboard/WooDeadlinePanel.vue` — Panel component listing Woo cases. Props: `cases: Array<{ id, identifier, title, deadline, daysRemaining, severity }>`, `loading: Boolean`, `error: String|null`. Title: "Woo Deadlines". Each row: identifier (bold), title, days remaining or "N days overdue" with severity color via `--color-error` / `--color-warning` / `--color-success`. Severity communicated by both color AND text label (WCAG). Click row emits `@click-case(id)`. Footer: "View all Woo cases" emits `@view-all`. Empty state: "No open Woo requests". Add SPDX header.
  - @spec openspec/changes/dashboard/tasks.md#T05

### Signalering Widgets

- [x] **T06** *(already shipped — `src/views/dashboard/DeadlineAlerts.vue` (in-app) + `src/views/widgets/DeadlineAlertsWidget.vue` (NC dashboard) with overdue/at-risk split, colour+label severity)*: Create `src/views/dashboard/DeadlineAlertsWidget.vue` — Widget displaying deadline alerts. Props: `alerts: Array<{ id, identifier, title, caseTypeName, daysRemaining, isOverdue, severity }>`, `loading: Boolean`. Two sections: "Overdue" (red) and "At Risk" (yellow) if both present; combined otherwise. Each row: identifier, title, case type (muted), severity badge. Row click navigates to case detail. Footer: "View all deadline alerts" emits `@view-all`. Empty state: "No deadline alerts". Add SPDX header.
  - @spec openspec/changes/dashboard/tasks.md#T06

- [x] **T07** *(already shipped — `src/views/dashboard/TaskDueReminders.vue` + `src/views/widgets/TaskRemindersWidget.vue`)*: Create `src/views/dashboard/TaskDueRemindersWidget.vue` — Widget showing task due reminders for the current user. Props: `tasks: Array<{ id, title, caseId, caseIdentifier, daysRemaining, isOverdue, priority }>`, `loading: Boolean`. Each row: task title, case reference (muted), "Due today" / "N days" / "N days overdue" with color severity, priority icon (high/urgent). Row click emits `@click-task(id)`. Empty state: "No task reminders". Add SPDX header.
  - @spec openspec/changes/dashboard/tasks.md#T07

- [x] **T08** *(already shipped — `src/views/dashboard/StalledCases.vue` + `src/views/widgets/StalledCasesWidget.vue`)*: Create `src/views/dashboard/StalledCasesWidget.vue` — Widget showing stalled open cases. Props: `cases: Array<{ id, identifier, title, caseTypeName, daysSinceUpdate, assignee }>`, `loading: Boolean`. Each row: identifier, title, case type, "N days without update" (muted). Row click emits `@click-case(id)`. Footer: "View all stalled cases" emits `@view-all`. Empty state: "No stalled cases". Add SPDX header.
  - @spec openspec/changes/dashboard/tasks.md#T08

- [~] **T09** *(DEFERRED — the three signalering widgets already ship as both in-app dashboard components AND NC-dashboard widgets. A dedicated `SignaleringSection.vue` grid container only makes sense once the orphaned in-app `src/views/dashboard/*` components are re-hosted into the manifest-v2 Dashboard page; that re-hosting is a separate concern (the manifest Dashboard page currently renders lib placeholder body widgets). Tracked as the wiring follow-up below.)*: Create `src/views/dashboard/SignaleringSection.vue` — Container that renders all three signalering widgets in a responsive CSS Grid (`repeat(auto-fit, minmax(300px, 1fr))`). Props: `alerts`, `taskReminders`, `stalledCases` arrays with corresponding `loading` booleans. Forward `@click-case`, `@click-task`, `@view-all` events to Dashboard.vue. Title: "Signalering". Add SPDX header.
  - @spec openspec/changes/dashboard/tasks.md#T09

### Dashboard.vue Extensions

- [~] **T10** *(N/A as written — there is no `src/views/Dashboard.vue` in the manifest-v2 shell; the Dashboard is a declarative `type:"dashboard"` page in `src/manifest.json`. The "View Analytics" navigation (sub-task g) is delivered via the new `Analytics` menu entry → existing Doorlooptijd page. Re-hosting CaseTypeChart/Woo/Signalering into the manifest Dashboard grid is the wiring follow-up.)*: Extend `src/views/Dashboard.vue` — (a) Import and register `CaseTypeChart`, `WooDeadlinePanel`, `SignaleringSection`. (b) Add `typeData`, `wooAlerts`, `deadlineAlerts`, `taskReminders`, `stalledCases` to component data. (c) In `loadDashboardData()`, compute `typeData` via `aggregateByType(openCases, caseTypes)` (sort by count desc), `wooAlerts` via `getWooCases()`, `deadlineAlerts` via `getDeadlineAlerts()`, `taskReminders` via `getTaskReminders()`, `stalledCases` via `getStalledCases()` — all from already-fetched data (no new API calls). (d) Insert `CaseTypeChart` after `StatusChart` in the template. (e) Insert `WooDeadlinePanel` in the right column. (f) Insert `SignaleringSection` below the two-column section. (g) Add a "View Analytics" link/button in the dashboard header that navigates to `/dashboard/analytics`. (h) Handle all emitted events: `@click-case → $router.push`, `@view-all → $router.push` with appropriate filters.
  - @spec openspec/changes/dashboard/tasks.md#T10

- [x] **T11** *(done — added to `src/utils/dashboardHelpers.js`; returns `[{ type, count }]` sorted by count desc, mirroring `aggregateByStatus`)*: Add `aggregateByType(openCases, caseTypes)` to `src/utils/dashboardHelpers.js` — Groups open cases by caseType name, returns `[{ name, count }]` sorted by count descending. Similar to existing `aggregateByStatus`.
  - @spec openspec/changes/dashboard/tasks.md#T11

### Process Analytics View

- [x] **T12** *(already shipped — the SLA-compliance KPI card + donut "Compliance by Case Type" live in `src/views/DoorlooptijdDashboard.vue` (computeSlaCompliance), with the "N% / N within SLA / N excluded — no SLA target" labels and a "No data" empty state. Uses the app's existing ApexCharts wrapper, not CnChartWidget, matching the in-repo charting convention.)*: Create `src/views/analytics/SlaComplianceWidget.vue` — SLA compliance donut chart using `CnChartWidget`. Props: `withinSla: Number`, `total: Number`, `excluded: Number`, `loading: Boolean`. Shows "N%" as central label, "N / total within SLA" sub-label. When `total === 0`, shows "No data". Shows excluded note "N cases excluded — no SLA target" when `excluded > 0`. Uses `CnChartWidget` with `type="donut"` from `@conduction/nextcloud-vue`. Add SPDX header.
  - @spec openspec/changes/dashboard/tasks.md#T12

- [x] **T13** *(already shipped — "Performance by Case Type" sortable table in `DoorlooptijdDashboard.vue` (computePerformanceTable) with case type, completed count, within-SLA, compliance %, avg days, SLA target; "—" for zero-total rows.)*: Create `src/views/analytics/CaseTypeBreakdownTable.vue` — Table displaying SLA compliance per case type. Props: `rows: Array<{ name, total, withinSla, withinSlaPct, avgDays, targetDays }>`, `loading: Boolean`. Columns: Case Type | Completed | Within SLA | Compliance % | Avg Days | SLA Target. Uses `CnDataTable` from `@conduction/nextcloud-vue`. Rows with `total === 0` show "—" for compliance metrics. Add SPDX header.
  - @spec openspec/changes/dashboard/tasks.md#T13

- [x] **T14** *(done — added a "Throughput (cases closed per week)" ApexCharts line chart to `DoorlooptijdDashboard.vue`, backed by the new `computeWeeklyThroughput(completedCases, 12)` helper (W## YYYY labels, trailing 12 weeks, empty state).)*: Create `src/views/analytics/ThroughputChart.vue` — Line chart of cases closed per week. Props: `weeks: Array<{ weekLabel: String, count: Number }>`, `loading: Boolean`, `error: String|null`. Uses `CnChartWidget` with `type="line"`. X-axis: week labels (e.g., "W15 2026"). Y-axis: case count. Empty state if `weeks.length === 0`. Add SPDX header.
  - @spec openspec/changes/dashboard/tasks.md#T14

- [x] **T15** *(satisfied by the existing `DoorlooptijdDashboard.vue` page — the canonical Process Analytics surface, registered in `registry.js`/`manifest.json` as page id `Doorlooptijd` and now reachable via the new `Analytics` menu entry. It loads cases+caseTypes+statusTypes in parallel (Promise.allSettled), computes SLA compliance/breakdown/trend, has a date-range preset filter and a case-type filter, and now the throughput chart + Woo panel. A duplicate `src/views/analytics/ProcessAnalytics.vue` would fork this logic — not built, per ADR-012 dedup.)*: Create `src/views/analytics/ProcessAnalytics.vue` — Full analytics page at `/dashboard/analytics`. (a) On `mounted()`, fire parallel queries: `fetchCollection('case', { endDate_gte: rangeStart, endDate_lte: rangeEnd })` for completed cases, plus fetch caseTypes and statusTypes. (b) Compute SLA compliance: for each completed case, parse `caseType.processingDeadline` (ISO 8601 → days), compare against `(endDate - startDate)` in days. Exclude cases with no SLA target. (c) Compute CaseType breakdown rows. (d) Compute throughput: group completed cases by ISO week of `endDate`, count per week, take trailing 12 weeks from selected end date. (e) Render `SlaComplianceWidget`, `CaseTypeBreakdownTable`, `ThroughputChart` components. (f) Date range filter: NcDatetimePicker or `<select>` with options "Last 30 days", "Last 3 months", "Last 6 months", "Last 12 months". (g) Page title "Process Analytics" via `CnPageHeader`. Add SPDX header.
  - @spec openspec/changes/dashboard/tasks.md#T15

### Workflow Board View

- [x] **T16** *(done — new file; draggable card, dragstart sets dataTransfer + emits caseId, click emits caseId, identifier/title/type-chip/assignee/deadline indicator with colour+label)*: Create `src/views/workflow-board/CaseCard.vue` — Draggable Kanban card. Props: `case: Object` (id, identifier, title, caseType, assignee, deadline). Draggable: `draggable="true"`, `@dragstart` emits `@dragstart(caseId)`. Click emits `@click(caseId)`. Shows: identifier badge, truncated title, case type chip, assignee avatar/name, deadline indicator (color: `--color-error` if overdue, `--color-warning` if within 3 days). Add SPDX header.
  - @spec openspec/changes/dashboard/tasks.md#T16

- [x] **T17** *(done — new file; dragover/drop handlers emit `drop(caseId, statusId)`, header with name + count badge, scrollable list (max-height calc, min-width 240px), empty-state placeholder)*: Create `src/views/workflow-board/BoardColumn.vue` — Single Kanban column. Props: `statusType: Object` (id, name, order), `cases: Array`, `loading: Boolean`. Accepts drag-and-drop: `@dragover.prevent`, `@drop` emits `@drop(draggedCaseId, statusType.id)`. Column header: status name + count badge. Scrollable case card list (`overflow-y: auto`, `max-height: calc(100vh - 200px)`). Empty state: muted placeholder text. Min-width: 240px. Add SPDX header.
  - @spec openspec/changes/dashboard/tasks.md#T17

- [x] **T18** *(done — new file; parallel fetch of statusType/caseType/case, non-final columns sorted by order, cases grouped by status, optimistic drag-to-advance via `saveObject('case', { ...case, status })` (the real ObjectStore API — there is no `updateObject(id, partial)`; matches QuickStatusDropdown), revert + `showError` toast on failure, click → CaseDetail)*: Create `src/views/workflow-board/WorkflowBoard.vue` — Main Kanban board at `/workflow-board`. (a) On `mounted()`, fetch open cases and non-final status types in parallel. (b) Sort status types by `order`. (c) Group open cases by current `status` ID. (d) Render `BoardColumn` for each non-final status type, passing the filtered case array. (e) Handle `@drop(caseId, newStatusId)`: call `caseStore.updateObject(caseId, { status: newStatusId })` wrapped in `try/catch`. On success, move card in local state. On failure, revert local state and show error toast. (f) Handle `@click` from `CaseCard`: `$router.push` to case detail. (g) Page title "Workflow Board" via `CnPageHeader`. `draggedCaseId` tracked in component data. Add SPDX header.
  - @spec openspec/changes/dashboard/tasks.md#T18

### Routing and Navigation

- [x] **T19** *(done via manifest-v2 equivalent — no `src/router/router.js` exists. Added the `WorkflowBoard` page (`type:"dashboard"` custom slot → `WorkflowBoardView`) to `src/manifest.json` + `src/registry.js`. The Analytics route reuses the existing `Doorlooptijd` page. No wildcard-ordering concern in the manifest model.)*: Update `src/router/router.js` — Add named route `{ path: '/dashboard/analytics', name: 'DashboardAnalytics', component: ProcessAnalytics }` and `{ path: '/workflow-board', name: 'WorkflowBoard', component: WorkflowBoard }`. Import both components. Ensure specific routes are added BEFORE any wildcard `{slug}` route per ADR-003.
  - @spec openspec/changes/dashboard/tasks.md#T19

- [x] **T20** *(done via manifest-v2 equivalent — no `MainMenu.vue` exists. Added two `menu[]` entries to `src/manifest.json`: "Workflow Board" (icon-category-workflow, route WorkflowBoard, order 35) and "Analytics" (icon-category-monitoring, route Doorlooptijd, order 55). Manifest icons are NC icon classes, not @mdi/js imports.)*: Update `src/components/MainMenu.vue` — Add navigation items: "Analytics" (icon: `chart-bar`) linking to `/dashboard/analytics`, and "Workflow Board" (icon: `view-column`) linking to `/workflow-board`. Import `mdiChartBar` and `mdiViewColumn` from `@mdi/js` via `CnIcon`.
  - @spec openspec/changes/dashboard/tasks.md#T20

### Translations

- [x] **T21** *(done — added 18 new keys to en.json/en_US.json/nl.json and regenerated the matching en.js/en_US.js/nl.js; English keys, Dutch values; all 6 files validate (node --check + json.tool). Pre-existing keys like "Cases by Type", "{days} days remaining" reused.)*: Update `l10n/en.json` and `l10n/nl.json` — Add translation keys for all new user-visible strings: "Cases by Type", "Woo Deadlines", "No open Woo requests", "Signalering", "No deadline alerts", "No task reminders", "No stalled cases", "Deadline Alerts", "Task Due Reminders", "Stalled Cases", "Process Analytics", "Workflow Board", "View Analytics", "N days overdue", "N days without update", "Due today", "At Risk", "No data", "N cases excluded — no SLA target", "Within SLA", "SLA Target", etc. Keys MUST be English; Dutch translations go in `nl.json`.
  - @spec openspec/changes/dashboard/tasks.md#T21

---

## Verification Tasks

> V-tasks marked `[~]` need a live Nextcloud instance + a webpack build (no
> node_modules in the hydra worktree). They are deferred to runtime verification
> by the reviewer/QA. Code-level checks were performed and ticked.

- [x] **V01**: Application.php registers all seven dashboard widgets (confirmed on HEAD — `registerWidgetsAndProviders`)
- [x] **V02**: All widget `getUrl()` use `.dashboard.page` (confirmed on HEAD across all 7 widget classes; route exists in `appinfo/routes.php`)
- [x] **V03**: Cases-by-Type bar widths are `max(20px, count/maxCount*100%)` (CasesByType.vue — code-verified)
- [x] **V04**: Clicking a bar `$router.push({ name: 'Cases', query: { caseType } })` (CasesByType.vue — code-verified)
- [x] **V05**: Deadline alerts overdue/at-risk split + sort (getDeadlineAlerts + DeadlineAlerts.vue — code-verified)
- [x] **V06**: Task reminders filter to dueDate within window, exclude terminal (getTaskDueReminders — code-verified)
- [x] **V07**: Stalled cases `>= 7d` since dateModified, sorted desc (getStalledCases — code-verified)
- [x] **V08**: Woo panel filters caseType title contains 'woo', empty-state "No open Woo requests" (getWooCases + WooDeadlinePanel.vue — code-verified)
- [x] **V09**: Severity uses colour AND text label everywhere (WooDeadlinePanel severityLabel, CaseCard deadlineLabel — code-verified)
- [~] **V10**: Process Analytics (Doorlooptijd page) renders SLA %, breakdown table, throughput chart — needs live build
- [~] **V11**: Date-range preset updates all charts/KPIs — needs live build
- [~] **V12**: Workflow Board columns match non-final status types — needs live build
- [~] **V13**: Drag-and-drop advances status; failure reverts + toasts — needs live build (logic code-verified: optimistic move + revert + showError)
- [x] **V14**: New strings present in en.json + nl.json (+ en_US, + regenerated .js) — validated
- [x] **V15**: No hardcoded hex in new files (`grep -nE '#[0-9a-fA-F]{3,6}'` → none) — verified
- [~] **V16**: Responsive grid at 768px — N/A for this build (SignaleringSection deferred, see T09); Woo panel + board use flex/responsive layout
- [x] **V17**: SPDX headers on all new files (`grep -rL 'SPDX-License-Identifier' src/views/workflow-board src/views/dashboard/WooDeadlinePanel.vue` → empty) — verified
- [x] **V18**: All `await store.*()` calls in new code wrapped in try/catch with user-facing error (WorkflowBoard onDrop + fetchData — verified)

---

## Wiring Follow-up (filed for the orphaned in-app dashboard components)

The `src/views/dashboard/*` components (CasesByType, DeadlineAlerts, StalledCases,
TaskDueReminders, StatusChart, KpiCards, MyWorkPreview, OverduePanel, ActivityFeed)
were built by `retrofit-2026-05-24-dashboard` but are **not rendered** by the
manifest-v2 Dashboard page, which currently shows lib placeholder body widgets.
Re-hosting them (plus the new WooDeadlinePanel + a SignaleringSection grid) into
the manifest Dashboard page — likely as a single custom Dashboard view registered
in `registry.js`, or via lib custom-widget slot resolution — is a focused
follow-up that should be its own change. It is independent of the Workflow Board
and Analytics surfaces delivered here, both of which are fully wired and reachable.
