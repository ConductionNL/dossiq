# Design: Dashboard

## Architecture Overview

This change extends the existing `Dashboard.vue` (implemented in dashboard-mvp) with V1 widgets and adds two new full-page views: Process Analytics (`/dashboard/analytics`) and Workflow Board (`/workflow-board`). All data flows through the existing Pinia object stores registered in `store.js`. No new schemas are introduced — only existing entities are consumed.

```
Dashboard.vue (extended)
├── KpiCards.vue            (existing — adds SLA Compliance 5th card)
├── StatusChart.vue         (existing)
├── CaseTypeChart.vue       (NEW — Cases by Type bar chart)
├── OverduePanel.vue        (existing)
├── WooDeadlinePanel.vue    (NEW — Woo statutory deadline countdown)
├── SignaleringSection.vue  (NEW — container for alerting widgets)
│   ├── DeadlineAlertsWidget.vue    (NEW)
│   ├── TaskDueRemindersWidget.vue  (NEW)
│   └── StalledCasesWidget.vue      (NEW)
├── MyWorkPreview.vue       (existing)
└── ActivityFeed.vue        (existing)

ProcessAnalytics.vue  (NEW — /dashboard/analytics)
├── SlaComplianceWidget.vue     (NEW — overall SLA % KPI)
├── CaseTypeBreakdownTable.vue  (NEW — per-type SLA compliance table)
└── ThroughputChart.vue         (NEW — cases closed per week, ApexCharts line)

WorkflowBoard.vue     (NEW — /workflow-board)
├── BoardColumn.vue             (NEW — one column per status type)
└── CaseCard.vue                (NEW — draggable case card)
```

## File Changes

### New Files

| File | Purpose |
|------|---------|
| `src/views/dashboard/CaseTypeChart.vue` | Horizontal CSS bar chart of open cases by case type, sorted by count descending |
| `src/views/dashboard/WooDeadlinePanel.vue` | Lists open Woo-type cases with statutory deadline countdown and traffic-light severity |
| `src/views/dashboard/SignaleringSection.vue` | Container that renders all three signalering widgets in a responsive grid |
| `src/views/dashboard/DeadlineAlertsWidget.vue` | Cases within warning threshold (default 3 days) or overdue, sorted by urgency |
| `src/views/dashboard/TaskDueRemindersWidget.vue` | Current user's tasks approaching or past due date |
| `src/views/dashboard/StalledCasesWidget.vue` | Open cases with no `updatedAt` change in 7+ days |
| `src/views/analytics/ProcessAnalytics.vue` | Full analytics page with SLA KPI, breakdown table, throughput chart |
| `src/views/analytics/SlaComplianceWidget.vue` | Donut chart showing % of completed cases within SLA target |
| `src/views/analytics/CaseTypeBreakdownTable.vue` | Table: case type, completed count, within-SLA count/%, avg days, SLA target |
| `src/views/analytics/ThroughputChart.vue` | Line chart of cases closed per week for the trailing 12 weeks |
| `src/views/workflow-board/WorkflowBoard.vue` | Kanban board: columns per status type, case cards, drag-to-advance |
| `src/views/workflow-board/BoardColumn.vue` | Single Kanban column: status type header, case count badge, scrollable card list |
| `src/views/workflow-board/CaseCard.vue` | Draggable case card: identifier, title, case type badge, assignee, deadline indicator |
| `src/utils/signaleringHelpers.js` | Pure utility functions: `getDeadlineAlerts()`, `getTaskReminders()`, `getStalledCases()`, `getWooCases()` |

### Modified Files

| File | Changes |
|------|---------|
| `src/views/Dashboard.vue` | Add CaseTypeChart, WooDeadlinePanel, SignaleringSection; pass SLA data to KpiCards; add doorlooptijd nav link |
| `src/router/router.js` | Add routes for `/dashboard/analytics` (ProcessAnalytics) and `/workflow-board` (WorkflowBoard) |
| `src/App.vue` or `MainMenu` | Add "Analytics" and "Workflow Board" navigation items |
| `lib/AppInfo/Application.php` | Register `CasesOverviewWidget`, `MyTasksWidget`, `OverdueCasesWidget` via `$context->registerDashboardWidget()`; fix `CasesOverviewWidget` route from `.dashboard.index` to `.dashboard.page` |
| `l10n/en.json` + `l10n/nl.json` | Add translation keys for all new strings |

### Unchanged Files

| File | Reason |
|------|--------|
| `src/store/modules/object.js` | `fetchCollection` already supports all required queries |
| `src/store/store.js` | Entities `case`, `task`, `caseType`, `statusType` already registered |
| `src/views/Dashboard.vue` (KpiCards, StatusChart, OverduePanel, etc.) | Existing sub-components reused as-is |
| `src/utils/dashboardHelpers.js` | Existing helpers (`computeKpis`, `getOverdueCases`, etc.) reused without modification |

## Design Decisions

### DD-01: Signalering helpers in a separate utility module

Signalering computations (`getDeadlineAlerts`, `getTaskReminders`, `getStalledCases`) are pure functions of data already loaded by Dashboard.vue on mount. Placing them in `signaleringHelpers.js` keeps them testable and avoids adding logic to the already-complex `dashboardHelpers.js`.

### DD-02: CnChartWidget for process analytics charts

The process analytics view uses `CnChartWidget` from `@conduction/nextcloud-vue` (ApexCharts wrapper) rather than the pure-CSS approach used in the MVP status chart. ApexCharts provides tooltips, responsive resizing, and series labeling needed for multi-series data (throughput line chart, SLA donut chart). The MVP chart remains CSS-only as it is sufficient for its single-series use case.

### DD-03: Woo detection via caseType title substring

Woo cases are identified by checking `caseType.title.toLowerCase().includes('woo')`. This avoids hardcoding UUIDs and survives re-imports. A future improvement can add a `tags` or `category` field to caseType, but for V1 the title match is pragmatic and sufficient.

### DD-04: Workflow board drag-to-advance uses objectStore.saveObject

Dragging a case card from one column to another calls `this.caseStore.updateObject(caseId, { status: newStatusId })` via the existing Pinia store. No new API endpoint. The store calls `ObjectService.saveObject()` which enforces RBAC. If the user lacks write permission, the save fails and the card snaps back.

### DD-05: Process analytics date range defaults to last 3 months

The analytics view defaults to a 90-day date range (`startDate >= (today - 90d)`) for the throughput chart and SLA breakdown. A date range picker (NcDatetimePicker) allows the user to adjust this. The SLA compliance rate KPI uses the same range. The default balances data richness with query performance.

### DD-06: Stalled Cases threshold — 7 calendar days

Stalled is defined as `(today - case.updatedAt) >= 7 days` for open cases. This matches the signalering-widgets spec default. The threshold is not user-configurable in V1; a future settings integration can expose it via `IAppConfig`.

## Component Props

### CaseTypeChart.vue
```
Props:
  typeData: Array<{ name: String, count: Number }>
  loading: Boolean
  error: String | null
Events:
  @click-bar(caseTypeName: String) — navigate to cases filtered by type
  @retry
```

### WooDeadlinePanel.vue
```
Props:
  cases: Array<{ id, identifier, title, deadline, daysRemaining, severity: 'ok'|'warning'|'critical'|'overdue' }>
  loading: Boolean
  error: String | null
Events:
  @click-case(caseId)
  @view-all
```

### DeadlineAlertsWidget.vue
```
Props:
  alerts: Array<{ id, identifier, title, caseTypeName, daysRemaining, isOverdue, severity }>
  loading: Boolean
Events:
  @click-case(caseId)
  @view-all
```

### TaskDueRemindersWidget.vue
```
Props:
  tasks: Array<{ id, title, caseId, caseIdentifier, daysRemaining, isOverdue, priority }>
  loading: Boolean
Events:
  @click-task(taskId)
```

### StalledCasesWidget.vue
```
Props:
  cases: Array<{ id, identifier, title, caseTypeName, daysSinceUpdate, assignee }>
  loading: Boolean
Events:
  @click-case(caseId)
  @view-all
```

### SlaComplianceWidget.vue
```
Props:
  withinSla: Number
  total: Number
  excluded: Number
  loading: Boolean
```

### ThroughputChart.vue
```
Props:
  weeks: Array<{ weekLabel: String, count: Number }>
  loading: Boolean
  error: String | null
```

### CaseTypeBreakdownTable.vue
```
Props:
  rows: Array<{ name, total, withinSla, withinSlaPct, avgDays, targetDays }>
  loading: Boolean
```

### BoardColumn.vue
```
Props:
  statusType: Object — { id, name, order, isFinal }
  cases: Array<case objects>
  loading: Boolean
Events:
  @drop(caseId, newStatusId) — when a card is dropped into this column
```

### CaseCard.vue
```
Props:
  case: Object — { id, identifier, title, caseType, assignee, deadline }
  draggable: Boolean
Events:
  @click(caseId)
  @dragstart(caseId)
```

## Data Flow

### signaleringHelpers.js functions

```javascript
getDeadlineAlerts(openCases, caseTypes, warningDays = 3)
  → Array<{ id, identifier, title, caseTypeName, daysRemaining, isOverdue, severity }>
  // Filters: deadline within warningDays OR deadline < today. Sorts: overdue first (most overdue), then soonest deadline.

getTaskReminders(tasks, warningDays = 3)
  → Array<{ id, title, caseId, caseIdentifier, daysRemaining, isOverdue, priority }>
  // Filters: task.dueDate within warningDays OR past. Excludes tasks with no dueDate.

getStalledCases(openCases, caseTypes, stalledDays = 7)
  → Array<{ id, identifier, title, caseTypeName, daysSinceUpdate, assignee }>
  // Filters: (today - updatedAt) >= stalledDays. Sorts: most stalled first.

getWooCases(openCases, caseTypes)
  → Array<{ id, identifier, title, deadline, daysRemaining, severity }>
  // Filters: caseType.title.toLowerCase().includes('woo'). Severity: overdue=red, ≤7d=orange, ≤14d=yellow, >14d=green.
```

### Process Analytics data flow

1. `ProcessAnalytics.vue` `mounted()` fires parallel queries via `Promise.allSettled`:
   - `fetchCollection('case', { _filters: { endDate_gte: rangeStart, endDate_lte: rangeEnd } })` — completed cases
   - `fetchCollection('caseType', { _limit: 100 })` — for SLA target resolution
   - `fetchCollection('statusType', { _limit: 500 })` — for `isFinal` flag
2. SLA compliance: for each completed case, compare `(endDate - startDate)` (days) against `caseType.processingDeadline` (ISO 8601 → days). Cases with no SLA target excluded.
3. Throughput: group completed cases by ISO week number of `endDate`, count per week, take trailing 12 weeks.
4. CaseType breakdown: group by caseType name, compute within-SLA count and avg processing days per type.

### Workflow Board data flow

1. `WorkflowBoard.vue` `mounted()` fetches open cases and status types in parallel.
2. Status types are sorted by `order`, non-final only (option to show final in a "Closed" column).
3. Cases are grouped by current `status` ID into each column's `cases` array.
4. Drag-and-drop: `@dragstart` sets `draggedCaseId` in component data; `@drop` on a `BoardColumn` calls `caseStore.updateObject(draggedCaseId, { status: column.statusType.id })`.

## Reuse Analysis

Per ADR-012 (Deduplication), the following OpenRegister and platform capabilities are leveraged rather than rebuilt:

| Capability | Source | Used for |
|-----------|--------|---------|
| `fetchCollection('case', filters)` | `ObjectService` (via Pinia store) | All dashboard data queries |
| `fetchCollection('task', filters)` | `ObjectService` | Task reminders widget data |
| `fetchCollection('caseType', ...)` | `ObjectService` | SLA target + Woo type detection |
| `fetchCollection('statusType', ...)` | `ObjectService` | isFinal detection, board columns |
| `CnChartWidget` (ApexCharts) | `@conduction/nextcloud-vue` | Process analytics charts |
| `CnDashboardPage` + GridStack | `@conduction/nextcloud-vue` | Workflow board layout |
| `createObjectStore` (Pinia) | `@conduction/nextcloud-vue` | Case status update on drag-to-advance |
| `useDashboardView` composable | `@conduction/nextcloud-vue` | Board layout state management |
| `dashboardHelpers.js` | Existing in-app utility | KPI computations reused by signalering |

No new custom chart components, CRUD controllers, or state management is built — all reuses platform-provided implementations.

## Seed Data

**Not required.** This change introduces no new schemas. All entities (`case`, `task`, `caseType`, `statusType`) are existing ADR-000 entities with seed data already defined in `lib/Settings/procest_register.json`. Per ADR-001 seed data rules, "changes that only modify frontend components or non-schema backend logic do not require seed data."

## Security Considerations

- All data queries flow through OpenRegister `ObjectService` which enforces RBAC — no additional auth required
- Workflow board drag-to-advance: `caseStore.updateObject()` calls backend which enforces `IGroupManager::isAdmin()` / ownership checks — frontend cannot bypass
- No new API endpoints are introduced
- No PII in analytics aggregates (counts and averages only, not individual case content)

## NL Design System

- Signalering severity colors: `--color-error` (overdue/critical), `--color-warning` (at-risk), `--color-success` (on track) — no hardcoded hex values
- Board column headers: `--color-primary-element` for active column, `--color-text-maxcontrast` for labels
- All chart colors: `CnChartWidget` inherits theme via CSS custom properties
- WCAG AA: severity is communicated by both color AND text label ("overdue", "at risk", "on track") — color alone is never the sole indicator
- Responsive: signalering section uses CSS Grid with `repeat(auto-fit, minmax(300px, 1fr))`, collapsing to single column on 768px
- Workflow board: horizontal scroll on narrow viewports; each column has `min-width: 240px`
