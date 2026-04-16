# Tasks: Dashboard

## Deduplication Check

- [ ] **DED-01**: Confirm no overlap with existing implementations — search `openspec/specs/` for existing dashboard, signalering, and analytics specs; verify `src/views/dashboard/`, `src/views/analytics/`, and `lib/AppInfo/Application.php` do not already contain the components and registrations listed below. **Findings**: `openspec/specs/dashboard/spec.md` (status: implemented) covers MVP only; V1 requirements are unimplemented. `openspec/specs/signalering-widgets/spec.md` and `openspec/specs/doorlooptijd-dashboard/spec.md` have no corresponding implementation files. `Application.php` is missing `registerDashboardWidget` calls for the three existing widget classes. No overlap with ObjectService, RegisterService, SchemaService, or ConfigurationService.

---

## Implementation Tasks

### Application.php Widget Registration (Fix)

- [ ] **T01**: Fix `lib/AppInfo/Application.php` — Add `$context->registerDashboardWidget(CasesOverviewWidget::class)`, `$context->registerDashboardWidget(MyTasksWidget::class)`, `$context->registerDashboardWidget(OverdueCasesWidget::class)` in the `register(IRegistrationContext $context)` method. Ensure all three widget class files are imported at the top of Application.php. Verify correct namespace for each widget class.
  - @spec openspec/changes/dashboard/tasks.md#T01

- [ ] **T02**: Fix `lib/Dashboard/CasesOverviewWidget.php` — Change the route reference from `.dashboard.index` to `.dashboard.page` in the widget's URL generation. Run `curl` on the rendered widget URL to verify the route resolves.
  - @spec openspec/changes/dashboard/tasks.md#T02

### Signalering Helpers

- [ ] **T03**: Create `src/utils/signaleringHelpers.js` — Export four pure functions:
  - `getDeadlineAlerts(openCases, caseTypes, warningDays = 3)`: filter cases where `deadline` is within `warningDays` days of today OR in the past. For each match, compute `daysRemaining` (negative if overdue) and `severity` ('overdue' | 'critical' | 'warning'). Sort: overdue first (most overdue), then ascending `daysRemaining`. Include: `{ id, identifier, title, caseTypeName, daysRemaining, isOverdue, severity }`.
  - `getTaskReminders(tasks, warningDays = 3)`: filter tasks where `dueDate` is within `warningDays` days or past. Exclude tasks with no `dueDate`. Compute `daysRemaining` and `isOverdue`. Sort: overdue first, then ascending `dueDate`. Include: `{ id, title, caseId, caseIdentifier, daysRemaining, isOverdue, priority }`.
  - `getStalledCases(openCases, caseTypes, stalledDays = 7)`: filter cases where `(today - updatedAt) >= stalledDays`. Compute `daysSinceUpdate`. Sort: most stalled first. Include: `{ id, identifier, title, caseTypeName, daysSinceUpdate, assignee }`.
  - `getWooCases(openCases, caseTypes)`: filter cases whose resolved `caseType.title` (case-insensitive) includes 'woo'. For each, compute `daysRemaining` from `deadline`, and `severity` ('overdue' | 'critical' ≤7d | 'warning' ≤14d | 'ok' >14d). Sort: overdue first, then ascending `daysRemaining`. Include: `{ id, identifier, title, deadline, daysRemaining, severity }`.
  - Use `new Date().toISOString().slice(0, 10)` for today. Case name resolution from `caseTypes` array by matching UUID.
  - @spec openspec/changes/dashboard/tasks.md#T03

### Cases by Type Chart

- [ ] **T04**: Create `src/views/dashboard/CaseTypeChart.vue` — Horizontal bar chart component using pure CSS (same pattern as `StatusChart.vue`). Props: `typeData: Array<{ name, count }>`, `loading: Boolean`, `error: String|null`. Title: "Cases by Type". Each bar: `div` with `width: (count / maxCount * 100)%` (minimum 20px), type name left-aligned, count right-aligned. Colors cycle from a 6-color CSS variable palette. Click on bar emits `@click-bar(name)`. Empty state: "No open cases". Loading: 4 skeleton bars. Error state: inline message with retry button. Add `<!-- SPDX-License-Identifier: EUPL-1.2 -->` as first line.
  - @spec openspec/changes/dashboard/tasks.md#T04

### Woo Deadline Panel

- [ ] **T05**: Create `src/views/dashboard/WooDeadlinePanel.vue` — Panel component listing Woo cases. Props: `cases: Array<{ id, identifier, title, deadline, daysRemaining, severity }>`, `loading: Boolean`, `error: String|null`. Title: "Woo Deadlines". Each row: identifier (bold), title, days remaining or "N days overdue" with severity color via `--color-error` / `--color-warning` / `--color-success`. Severity communicated by both color AND text label (WCAG). Click row emits `@click-case(id)`. Footer: "View all Woo cases" emits `@view-all`. Empty state: "No open Woo requests". Add SPDX header.
  - @spec openspec/changes/dashboard/tasks.md#T05

### Signalering Widgets

- [ ] **T06**: Create `src/views/dashboard/DeadlineAlertsWidget.vue` — Widget displaying deadline alerts. Props: `alerts: Array<{ id, identifier, title, caseTypeName, daysRemaining, isOverdue, severity }>`, `loading: Boolean`. Two sections: "Overdue" (red) and "At Risk" (yellow) if both present; combined otherwise. Each row: identifier, title, case type (muted), severity badge. Row click navigates to case detail. Footer: "View all deadline alerts" emits `@view-all`. Empty state: "No deadline alerts". Add SPDX header.
  - @spec openspec/changes/dashboard/tasks.md#T06

- [ ] **T07**: Create `src/views/dashboard/TaskDueRemindersWidget.vue` — Widget showing task due reminders for the current user. Props: `tasks: Array<{ id, title, caseId, caseIdentifier, daysRemaining, isOverdue, priority }>`, `loading: Boolean`. Each row: task title, case reference (muted), "Due today" / "N days" / "N days overdue" with color severity, priority icon (high/urgent). Row click emits `@click-task(id)`. Empty state: "No task reminders". Add SPDX header.
  - @spec openspec/changes/dashboard/tasks.md#T07

- [ ] **T08**: Create `src/views/dashboard/StalledCasesWidget.vue` — Widget showing stalled open cases. Props: `cases: Array<{ id, identifier, title, caseTypeName, daysSinceUpdate, assignee }>`, `loading: Boolean`. Each row: identifier, title, case type, "N days without update" (muted). Row click emits `@click-case(id)`. Footer: "View all stalled cases" emits `@view-all`. Empty state: "No stalled cases". Add SPDX header.
  - @spec openspec/changes/dashboard/tasks.md#T08

- [ ] **T09**: Create `src/views/dashboard/SignaleringSection.vue` — Container that renders all three signalering widgets in a responsive CSS Grid (`repeat(auto-fit, minmax(300px, 1fr))`). Props: `alerts`, `taskReminders`, `stalledCases` arrays with corresponding `loading` booleans. Forward `@click-case`, `@click-task`, `@view-all` events to Dashboard.vue. Title: "Signalering". Add SPDX header.
  - @spec openspec/changes/dashboard/tasks.md#T09

### Dashboard.vue Extensions

- [ ] **T10**: Extend `src/views/Dashboard.vue` — (a) Import and register `CaseTypeChart`, `WooDeadlinePanel`, `SignaleringSection`. (b) Add `typeData`, `wooAlerts`, `deadlineAlerts`, `taskReminders`, `stalledCases` to component data. (c) In `loadDashboardData()`, compute `typeData` via `aggregateByType(openCases, caseTypes)` (sort by count desc), `wooAlerts` via `getWooCases()`, `deadlineAlerts` via `getDeadlineAlerts()`, `taskReminders` via `getTaskReminders()`, `stalledCases` via `getStalledCases()` — all from already-fetched data (no new API calls). (d) Insert `CaseTypeChart` after `StatusChart` in the template. (e) Insert `WooDeadlinePanel` in the right column. (f) Insert `SignaleringSection` below the two-column section. (g) Add a "View Analytics" link/button in the dashboard header that navigates to `/dashboard/analytics`. (h) Handle all emitted events: `@click-case → $router.push`, `@view-all → $router.push` with appropriate filters.
  - @spec openspec/changes/dashboard/tasks.md#T10

- [ ] **T11**: Add `aggregateByType(openCases, caseTypes)` to `src/utils/dashboardHelpers.js` — Groups open cases by caseType name, returns `[{ name, count }]` sorted by count descending. Similar to existing `aggregateByStatus`.
  - @spec openspec/changes/dashboard/tasks.md#T11

### Process Analytics View

- [ ] **T12**: Create `src/views/analytics/SlaComplianceWidget.vue` — SLA compliance donut chart using `CnChartWidget`. Props: `withinSla: Number`, `total: Number`, `excluded: Number`, `loading: Boolean`. Shows "N%" as central label, "N / total within SLA" sub-label. When `total === 0`, shows "No data". Shows excluded note "N cases excluded — no SLA target" when `excluded > 0`. Uses `CnChartWidget` with `type="donut"` from `@conduction/nextcloud-vue`. Add SPDX header.
  - @spec openspec/changes/dashboard/tasks.md#T12

- [ ] **T13**: Create `src/views/analytics/CaseTypeBreakdownTable.vue` — Table displaying SLA compliance per case type. Props: `rows: Array<{ name, total, withinSla, withinSlaPct, avgDays, targetDays }>`, `loading: Boolean`. Columns: Case Type | Completed | Within SLA | Compliance % | Avg Days | SLA Target. Uses `CnDataTable` from `@conduction/nextcloud-vue`. Rows with `total === 0` show "—" for compliance metrics. Add SPDX header.
  - @spec openspec/changes/dashboard/tasks.md#T13

- [ ] **T14**: Create `src/views/analytics/ThroughputChart.vue` — Line chart of cases closed per week. Props: `weeks: Array<{ weekLabel: String, count: Number }>`, `loading: Boolean`, `error: String|null`. Uses `CnChartWidget` with `type="line"`. X-axis: week labels (e.g., "W15 2026"). Y-axis: case count. Empty state if `weeks.length === 0`. Add SPDX header.
  - @spec openspec/changes/dashboard/tasks.md#T14

- [ ] **T15**: Create `src/views/analytics/ProcessAnalytics.vue` — Full analytics page at `/dashboard/analytics`. (a) On `mounted()`, fire parallel queries: `fetchCollection('case', { endDate_gte: rangeStart, endDate_lte: rangeEnd })` for completed cases, plus fetch caseTypes and statusTypes. (b) Compute SLA compliance: for each completed case, parse `caseType.processingDeadline` (ISO 8601 → days), compare against `(endDate - startDate)` in days. Exclude cases with no SLA target. (c) Compute CaseType breakdown rows. (d) Compute throughput: group completed cases by ISO week of `endDate`, count per week, take trailing 12 weeks from selected end date. (e) Render `SlaComplianceWidget`, `CaseTypeBreakdownTable`, `ThroughputChart` components. (f) Date range filter: NcDatetimePicker or `<select>` with options "Last 30 days", "Last 3 months", "Last 6 months", "Last 12 months". (g) Page title "Process Analytics" via `CnPageHeader`. Add SPDX header.
  - @spec openspec/changes/dashboard/tasks.md#T15

### Workflow Board View

- [ ] **T16**: Create `src/views/workflow-board/CaseCard.vue` — Draggable Kanban card. Props: `case: Object` (id, identifier, title, caseType, assignee, deadline). Draggable: `draggable="true"`, `@dragstart` emits `@dragstart(caseId)`. Click emits `@click(caseId)`. Shows: identifier badge, truncated title, case type chip, assignee avatar/name, deadline indicator (color: `--color-error` if overdue, `--color-warning` if within 3 days). Add SPDX header.
  - @spec openspec/changes/dashboard/tasks.md#T16

- [ ] **T17**: Create `src/views/workflow-board/BoardColumn.vue` — Single Kanban column. Props: `statusType: Object` (id, name, order), `cases: Array`, `loading: Boolean`. Accepts drag-and-drop: `@dragover.prevent`, `@drop` emits `@drop(draggedCaseId, statusType.id)`. Column header: status name + count badge. Scrollable case card list (`overflow-y: auto`, `max-height: calc(100vh - 200px)`). Empty state: muted placeholder text. Min-width: 240px. Add SPDX header.
  - @spec openspec/changes/dashboard/tasks.md#T17

- [ ] **T18**: Create `src/views/workflow-board/WorkflowBoard.vue` — Main Kanban board at `/workflow-board`. (a) On `mounted()`, fetch open cases and non-final status types in parallel. (b) Sort status types by `order`. (c) Group open cases by current `status` ID. (d) Render `BoardColumn` for each non-final status type, passing the filtered case array. (e) Handle `@drop(caseId, newStatusId)`: call `caseStore.updateObject(caseId, { status: newStatusId })` wrapped in `try/catch`. On success, move card in local state. On failure, revert local state and show error toast. (f) Handle `@click` from `CaseCard`: `$router.push` to case detail. (g) Page title "Workflow Board" via `CnPageHeader`. `draggedCaseId` tracked in component data. Add SPDX header.
  - @spec openspec/changes/dashboard/tasks.md#T18

### Routing and Navigation

- [ ] **T19**: Update `src/router/router.js` — Add named route `{ path: '/dashboard/analytics', name: 'DashboardAnalytics', component: ProcessAnalytics }` and `{ path: '/workflow-board', name: 'WorkflowBoard', component: WorkflowBoard }`. Import both components. Ensure specific routes are added BEFORE any wildcard `{slug}` route per ADR-003.
  - @spec openspec/changes/dashboard/tasks.md#T19

- [ ] **T20**: Update `src/components/MainMenu.vue` — Add navigation items: "Analytics" (icon: `chart-bar`) linking to `/dashboard/analytics`, and "Workflow Board" (icon: `view-column`) linking to `/workflow-board`. Import `mdiChartBar` and `mdiViewColumn` from `@mdi/js` via `CnIcon`.
  - @spec openspec/changes/dashboard/tasks.md#T20

### Translations

- [ ] **T21**: Update `l10n/en.json` and `l10n/nl.json` — Add translation keys for all new user-visible strings: "Cases by Type", "Woo Deadlines", "No open Woo requests", "Signalering", "No deadline alerts", "No task reminders", "No stalled cases", "Deadline Alerts", "Task Due Reminders", "Stalled Cases", "Process Analytics", "Workflow Board", "View Analytics", "N days overdue", "N days without update", "Due today", "At Risk", "No data", "N cases excluded — no SLA target", "Within SLA", "SLA Target", etc. Keys MUST be English; Dutch translations go in `nl.json`.
  - @spec openspec/changes/dashboard/tasks.md#T21

---

## Verification Tasks

- [ ] **V01**: Application.php registers all three dashboard widgets — verify by navigating to `/apps/dashboard` and checking that Procest widgets appear in the widget picker
- [ ] **V02**: CasesOverviewWidget deep link resolves to correct route — click a link in the widget, verify no 404 or routing error
- [ ] **V03**: Cases by Type chart renders with bars proportional to open case counts per case type
- [ ] **V04**: Clicking a Cases by Type bar navigates to Cases view filtered by that type
- [ ] **V05**: Deadline Alerts widget shows cases within 3 days of deadline and overdue cases, sorted correctly
- [ ] **V06**: Task Due Reminders widget shows only current user's tasks with due dates within 3 days or past
- [ ] **V07**: Stalled Cases widget lists cases with no update in 7+ days, sorted by most stalled first
- [ ] **V08**: Woo Deadline panel appears for cases of a Woo case type; hides gracefully when no Woo cases exist
- [ ] **V09**: Severity indicators use both color AND text label (WCAG — color not sole indicator)
- [ ] **V10**: Process Analytics page at `/dashboard/analytics` loads and shows SLA compliance %, breakdown table, throughput chart
- [ ] **V11**: Date range filter on analytics page updates all charts/KPIs
- [ ] **V12**: Workflow Board at `/workflow-board` shows columns matching non-final status types
- [ ] **V13**: Drag-and-drop on workflow board updates case status; failure reverts card and shows error
- [ ] **V14**: All new strings translated in both `en.json` and `nl.json`
- [ ] **V15**: No hardcoded hex colors — all colors from CSS custom properties
- [ ] **V16**: Signalering section uses responsive CSS grid (verify on 768px viewport)
- [ ] **V17**: SPDX headers on all new files (`grep -rL 'SPDX-License-Identifier' src/ --include='*.vue'`)
- [ ] **V18**: All `await store.action()` calls wrapped in `try/catch` with user-facing error feedback
