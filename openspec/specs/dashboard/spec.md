# Dashboard Specification

## Purpose

The dashboard is the landing page of the Procest app. It provides an at-a-glance overview of case management activity: KPI cards with headline metrics, status and type distribution charts, an overdue cases panel, a personal workload preview, a recent activity feed, and quick actions. The dashboard aggregates data across all cases visible to the current user (respecting RBAC via OpenRegister).

**Feature tiers**: MVP (KPI cards, status chart, overdue panel, my work preview, activity feed, quick actions, empty state, refresh); V1 (average processing time KPI, case type breakdown chart, SLA compliance widget, workload distribution)

## Data Sources

All dashboard data comes from OpenRegister queries against the `procest` register:
- **Cases**: schema `case` — filtered by non-final status for "open", by `deadline < today` for "overdue", by `endDate` within current month for "completed this month"
- **Tasks**: schema `task` — filtered by `assignee == currentUser` and status `available` or `active`
- **Activity**: Nextcloud Activity API (`OCP\Activity\IManager`) — filtered by app `procest`, last 10 events
- **SLA metrics**: derived from case type `processingDeadline` vs actual processing time per case

## Requirements

### REQ-DASH-001: KPI Cards Row [MVP]

The dashboard MUST display a row of five KPI cards at the top, providing headline metrics for the current user's case management workload. The fifth card shows SLA Compliance.

#### Scenario DASH-001a: Open cases count with today indicator
- GIVEN there are 24 cases with non-final status visible to the current user
- AND 3 of those cases were created today (startDate == today)
- WHEN the user views the dashboard
- THEN the system MUST display a KPI card titled "Open Cases"
- AND the card MUST show the count "24"
- AND the card MUST show a sub-label "+3 today"
- AND the count MUST only include cases whose current status is not marked `isFinal`

#### Scenario DASH-001b: Overdue cases count with action indicator
- GIVEN there are 3 cases where `deadline < today` and status is not final
- WHEN the user views the dashboard
- THEN the system MUST display a KPI card titled "Overdue"
- AND the card MUST show the count "3"
- AND the card MUST show a warning sub-label "action needed" to indicate urgency
- AND clicking the card MUST navigate to the Cases view filtered by `overdue=true`

#### Scenario DASH-001c: Completed this month with average processing days
- GIVEN 12 cases reached a final status during the current calendar month
- AND those 12 cases had an average duration of 18 days (from `startDate` to `endDate`)
- WHEN the user views the dashboard
- THEN the system MUST display a KPI card titled "Completed This Month"
- AND the card MUST show the count "12"
- AND the card MUST show a sub-label "avg 18 days"

#### Scenario DASH-001d: My tasks count with due-today indicator
- GIVEN the current user has 7 tasks assigned with status `available` or `active`
- AND 2 of those tasks have `dueDate == today`
- WHEN the user views the dashboard
- THEN the system MUST display a KPI card titled "My Tasks"
- AND the card MUST show the count "7"
- AND the card MUST show a sub-label "2 due today"

#### Scenario DASH-001e: SLA Compliance KPI card
- GIVEN 82 out of 100 completed cases this month were within their SLA target
- WHEN the user views the dashboard
- THEN the system MUST display a fifth KPI card titled "SLA Compliance"
- AND the card MUST show "82%"
- AND clicking the card MUST navigate to the doorlooptijd dashboard

#### Scenario DASH-001f: Zero values in KPI cards
- GIVEN no cases exist in the system
- WHEN the user views the dashboard
- THEN each KPI card MUST show "0" as the count (or "—" for SLA Compliance)
- AND sub-labels MUST either show "0 today" / "none" or be omitted gracefully
- AND the cards MUST NOT show errors or broken layouts

### REQ-DASH-010: Doorlooptijd Navigation Link [V1]

The main dashboard SHALL provide a visible navigation element to access the doorlooptijd analytics view.

#### Scenario DASH-010a: Navigation tab to doorlooptijd
- GIVEN the user views the main dashboard
- WHEN the dashboard loads
- THEN the system MUST display a tab or button labeled "Doorlooptijd" in the dashboard header area
- AND clicking it MUST navigate to the `/doorlooptijd` route

### REQ-DASH-002: Cases by Status Chart [MVP]

The dashboard MUST display a horizontal bar chart showing the distribution of open cases across status types.

#### Scenario DASH-002a: Status distribution with multiple statuses
- GIVEN open cases distributed as: Ontvangen (8), In behandeling (6), Besluitvorming (5), Bezwaar (3), Afgehandeld today (2)
- WHEN the user views the dashboard
- THEN the system MUST display a horizontal bar chart titled "Cases by Status"
- AND each bar MUST show the status name on the left and the count on the right
- AND bars MUST be ordered by status order from case types for consistency
- AND each bar's length MUST be proportional to its count relative to the maximum

#### Scenario DASH-002b: Statuses with zero cases
- GIVEN a status type "Bezwaar" exists but no cases currently have that status
- WHEN the user views the status chart
- THEN the system MAY omit statuses with zero cases from the chart
- OR the system MAY show them with an empty bar and count "0"

#### Scenario DASH-002c: Multiple case types with same-named statuses
- GIVEN case type "Omgevingsvergunning" has status "In behandeling" (3 cases)
- AND case type "Subsidieaanvraag" also has status "In behandeling" (4 cases)
- WHEN the user views the status chart
- THEN the system MUST aggregate cases by status name across case types
- AND the chart MUST show "In behandeling" with count 7

#### Scenario DASH-002d: Status chart color coding
- GIVEN the status chart displays 4 status types
- WHEN the user views the chart
- THEN each bar MUST use a distinct color from the Nextcloud theme palette
- AND the colors MUST be consistent across dashboard refreshes
- AND the colors MUST meet WCAG AA contrast requirements against the bar background

#### Scenario DASH-002e: Status chart click navigation
- GIVEN a status bar "In behandeling" with count 7
- WHEN the user clicks on the bar
- THEN the system SHOULD navigate to the Cases view filtered by `status=In behandeling`

### REQ-DASH-003: Cases by Type Chart [V1]

The dashboard SHALL display a bar chart showing the distribution of open cases by case type.

#### Scenario DASH-003a: Case type distribution
- GIVEN open cases distributed as: Omgevingsvergunning (10), Subsidieaanvraag (7), Klacht (4), Melding (3)
- WHEN the user views the dashboard
- THEN the system MUST display a bar chart titled "Cases by Type"
- AND each bar MUST show the case type title and the count
- AND bars MUST be ordered by count descending

#### Scenario DASH-003b: Case type with no open cases
- GIVEN a published case type "Bezwaarschrift" exists but has no open cases
- WHEN the user views the case type chart
- THEN the system MAY omit types with zero open cases
- OR the system MAY show them with a zero-count bar

#### Scenario DASH-003c: Click through to filtered case list
- GIVEN a bar "Omgevingsvergunning" with count 10
- WHEN the user clicks on the bar
- THEN the system MUST navigate to the Cases view filtered by `type=Omgevingsvergunning`

### REQ-DASH-004: Overdue Cases Panel [MVP]

The dashboard MUST display a panel listing cases that have exceeded their processing deadline.

#### Scenario DASH-004a: Overdue cases list with details
- GIVEN the following overdue cases:
  | identifier | title                    | caseType             | daysOverdue | assignee |
  |------------|--------------------------|----------------------|-------------|----------|
  | 2024-042   | Bouwvergunning Keizersgr | Omgevingsvergunning  | 5           | Jan      |
  | 2024-038   | Subsidie innovatie       | Subsidieaanvraag     | 2           | Maria    |
- AND case #2024-045 "Klacht behandeling" is due tomorrow (not yet overdue)
- WHEN the user views the dashboard
- THEN the system MUST display an "Overdue Cases" panel
- AND the panel MUST list each overdue case showing: identifier, title, case type, days overdue, and handler name
- AND cases MUST be sorted by days overdue descending (most overdue first)
- AND case #2024-045 MUST NOT appear in this panel (it is not yet overdue)

#### Scenario DASH-004b: Overdue case visual severity
- GIVEN a case that is 5 days overdue
- AND a case that is due tomorrow (1 day remaining)
- WHEN the user views the overdue panel
- THEN overdue cases MUST be displayed with a red indicator
- AND cases due within 1 day MAY be displayed with a yellow/warning indicator in a separate "at risk" section or alongside overdue cases

#### Scenario DASH-004c: Overdue panel with "view all" link
- GIVEN there are 8 overdue cases
- WHEN the user views the dashboard
- THEN the panel MUST show all overdue cases (or a scrollable list if many)
- AND the panel MUST include a "View all overdue" link that navigates to the case list filtered by overdue status

#### Scenario DASH-004d: No overdue cases
- GIVEN all open cases have `deadline >= today`
- WHEN the user views the dashboard
- THEN the overdue panel MUST display a positive message (e.g., "No overdue cases") or be hidden
- AND the KPI card for overdue MUST show "0"

#### Scenario DASH-004e: Overdue panel row click navigates to case
- GIVEN the overdue panel shows case "2024-042"
- WHEN the user clicks on the row
- THEN the system MUST navigate to the case detail view for "2024-042"

### REQ-DASH-005: My Work Preview [MVP]

The dashboard MUST display a preview of the current user's personal workload, showing the top 5 most urgent items.

#### Scenario DASH-005a: My Work preview shows top 5 items
- GIVEN the current user is handler on 3 cases and has 4 tasks assigned
- WHEN the user views the dashboard
- THEN the system MUST display a "My Work" preview panel showing the top 5 items
- AND items MUST be sorted by priority (urgent first), then deadline/dueDate (soonest first)
- AND each item MUST show: entity type badge ([CASE] or [TASK]), title, case type or parent case reference, deadline/dueDate, and overdue status if applicable

#### Scenario DASH-005b: My Work preview link to full view
- GIVEN the My Work preview is displayed
- WHEN the user clicks "View all my work"
- THEN the system MUST navigate to the full My Work view

#### Scenario DASH-005c: My Work preview with no items
- GIVEN the current user has no assigned cases or tasks
- WHEN the user views the dashboard
- THEN the My Work preview MUST display a message such as "No items assigned to you"

#### Scenario DASH-005d: My Work item click navigates to detail
- GIVEN a task "Review docs" in the My Work preview
- WHEN the user clicks the item
- THEN the system MUST navigate to the task detail view

#### Scenario DASH-005e: My Work overdue highlighting
- GIVEN a case in My Work with deadline 3 days ago
- WHEN displayed in the preview
- THEN the item MUST show "3 days overdue" with a red/error visual indicator
- AND the overdue badge MUST be distinguishable from non-overdue items

### REQ-DASH-006: Recent Activity Feed [MVP]

The dashboard MUST display a feed of the last 10 case management events.

#### Scenario DASH-006a: Activity feed shows recent events
- GIVEN the following recent events occurred:
  1. Case #042 status changed to "In behandeling" by Jan (10 min ago)
  2. Decision recorded on Case #036 "Vergunning verleend" by Maria (1 hour ago)
  3. Task "Review docs" completed by Pieter (2 hours ago)
  4. Document "Situatietekening" uploaded on Case #042 (yesterday)
- WHEN the user views the dashboard
- THEN the system MUST display a "Recent Activity" feed
- AND the feed MUST show the last 10 events ordered by timestamp descending (most recent first)
- AND each event MUST show: event description, actor name, and relative timestamp
- AND the event types displayed MUST include: status changes, task completions, decisions, document uploads

#### Scenario DASH-006b: Activity feed "view all" link
- GIVEN the activity feed is displayed
- WHEN the user clicks "View all activity"
- THEN the system MUST navigate to a full activity view or the Nextcloud activity app filtered to Procest events

#### Scenario DASH-006c: Activity feed with no events
- GIVEN no Procest activity events have been recorded
- WHEN the user views the dashboard
- THEN the activity feed MUST display a message such as "No recent activity"

#### Scenario DASH-006d: Activity event links to source
- GIVEN an activity event "Case #042 status changed to In behandeling"
- WHEN the user clicks the event
- THEN the system MUST navigate to the case detail for case #042

#### Scenario DASH-006e: Activity feed groups same-day events
- GIVEN 5 events occurred today and 3 events occurred yesterday
- WHEN the user views the activity feed
- THEN events SHOULD be grouped under date headers ("Today", "Yesterday", date labels)
- AND within each group events MUST be ordered by timestamp descending

### REQ-DASH-007: Quick Actions [MVP]

The dashboard MUST provide quick action buttons for common case management tasks.

#### Scenario DASH-007a: New Case button
- GIVEN the user is on the dashboard
- WHEN they click the "+ New Case" button
- THEN the system MUST open the case creation dialog
- AND the case creation dialog MUST allow case type selection and title entry

#### Scenario DASH-007b: New Task button
- GIVEN the user is on the dashboard
- WHEN they click the "+ New Task" button
- THEN the system MUST open the task creation dialog

#### Scenario DASH-007c: Quick action visibility
- GIVEN the user is on the dashboard
- THEN the "+ New Case" button MUST be prominently visible as a primary action in the header area
- AND the "+ New Task" button MUST be available alongside it
- AND a refresh button MUST be visible with a spinning animation while loading

### REQ-DASH-008: Dashboard Data Scope [MVP]

The dashboard MUST aggregate data across all cases visible to the current user, respecting RBAC.

#### Scenario DASH-008a: Dashboard respects user permissions
- GIVEN user "Jan" has access to 20 cases via RBAC
- AND user "Maria" has access to 15 cases (some overlapping with Jan's)
- WHEN Jan views the dashboard
- THEN all counts, charts, and panels MUST reflect only the 20 cases Jan can access
- AND the system MUST NOT expose data from cases Jan cannot access

#### Scenario DASH-008b: Admin sees all cases
- GIVEN an admin user has access to all 50 cases in the system
- WHEN the admin views the dashboard
- THEN all dashboard metrics MUST reflect all 50 cases

#### Scenario DASH-008c: User group scoping
- GIVEN a user belongs to group "team-subsidies" with 12 assigned cases
- AND the dashboard filters by the user's team when group-scoped view is active
- WHEN the user views the dashboard
- THEN KPI cards MUST reflect only the 12 team cases
- AND a scope toggle (personal/team/all) MAY be provided

### REQ-DASH-009: Empty State [MVP]

The dashboard MUST display a helpful setup message when no cases exist.

#### Scenario DASH-009a: Fresh installation with no data
- GIVEN Procest was just installed and no cases or case types exist
- WHEN the user views the dashboard
- THEN the system MUST display an empty state with:
  - A friendly message explaining what Procest does (e.g., "Welcome to Procest - Case Management for Nextcloud")
  - A call-to-action to create the first case type (for admins) or inform non-admins that the app needs configuration
  - Helpful guidance or a link to documentation
- AND all KPI cards MUST show "0" without errors
- AND charts MUST either be hidden or show an empty state

#### Scenario DASH-009b: Cases exist but user has no access
- GIVEN cases exist but the current user has no RBAC access to any of them
- WHEN the user views the dashboard
- THEN the dashboard MUST show zero values and empty panels
- AND the system SHOULD display a message such as "You have no cases assigned yet"

#### Scenario DASH-009c: Admin empty state shows setup guidance
- GIVEN Procest is freshly installed and the user is an admin
- WHEN the admin views the dashboard
- THEN the empty state MUST include a "Configure Case Types" button linking to admin settings
- AND the guidance MUST explain the setup flow: create case type, add statuses, then create cases

### REQ-DASH-010: Dashboard Refresh Behavior [MVP]

The dashboard MUST load data on mount and support manual refresh.

#### Scenario DASH-010a: Dashboard loads data on mount
- GIVEN the user navigates to the dashboard
- WHEN the dashboard component mounts
- THEN the system MUST fetch all dashboard data (KPI metrics, chart data, overdue list, my work items, activity feed) from the API using `Promise.allSettled` for resilient parallel fetching
- AND the system MUST show loading skeletons or spinners while data is being fetched
- AND the system MUST NOT display stale data from a previous session

#### Scenario DASH-010b: Manual refresh button
- GIVEN the user is viewing the dashboard
- WHEN they click the refresh button
- THEN the system MUST re-fetch all dashboard data from the API
- AND the refresh button MUST show a spinning animation during the refresh
- AND the data displayed MUST reflect the current state after refresh completes

#### Scenario DASH-010c: API error during dashboard load
- GIVEN the OpenRegister API is temporarily unavailable
- WHEN the user navigates to the dashboard
- THEN the system MUST display an error message (e.g., "Unable to load dashboard data")
- AND the system MUST provide a retry option
- AND the system MUST NOT display partial or misleading data

#### Scenario DASH-010d: Auto-refresh interval
- GIVEN the user is viewing the dashboard
- WHEN 5 minutes have elapsed since the last data load
- THEN the system MUST automatically re-fetch dashboard data
- AND the auto-refresh MUST NOT interrupt user interaction (no full-page reload)
- AND the interval timer MUST be cleared when the component unmounts

#### Scenario DASH-010e: Partial data load failure resilience
- GIVEN the cases API returns data but the activity API fails
- WHEN the user views the dashboard
- THEN the system MUST display the available data (KPI cards, charts)
- AND the failed section (activity feed) MUST show a localized error message with a retry option
- AND the system MUST NOT block the entire dashboard due to a single section failure

### REQ-DASH-011: Average Processing Time KPI [V1]

The dashboard SHALL display the average processing time across completed cases.

#### Scenario DASH-011a: Average processing time calculation
- GIVEN 12 cases were completed this month with durations: 14, 16, 18, 20, 22, 15, 17, 19, 21, 13, 19, 22 days
- WHEN the user views the dashboard
- THEN the "Completed This Month" KPI card MUST show the average duration as "avg 18 days"
- AND the average MUST be calculated as the arithmetic mean of `endDate - startDate` for all cases completed in the current calendar month

#### Scenario DASH-011b: No completed cases this month
- GIVEN no cases have reached a final status in the current calendar month
- WHEN the user views the dashboard
- THEN the "Completed This Month" KPI card MUST show "0"
- AND the average sub-label MUST show "no data" or be omitted

#### Scenario DASH-011c: Average processing time trend
- GIVEN last month's average was 22 days and this month's is 18 days
- WHEN the user views the KPI card
- THEN the system MAY show a trend indicator (e.g., green down arrow indicating improvement)

### REQ-DASH-012: SLA Compliance Widget [V1]

The dashboard SHALL display an SLA compliance metric showing the percentage of cases completed within their processing deadline.

#### Scenario DASH-012a: SLA compliance percentage
- GIVEN 50 cases were completed in the last 30 days
- AND 42 of those were completed before their deadline
- WHEN the user views the dashboard
- THEN the SLA widget MUST show "84% on time" (42/50)
- AND the widget MUST use a green indicator for >= 80%, yellow for 60-79%, red for < 60%

#### Scenario DASH-012b: SLA compliance by case type
- GIVEN SLA compliance rates: Omgevingsvergunning 90%, Subsidieaanvraag 75%, Klacht 60%
- WHEN the user views the SLA widget detail
- THEN the system SHOULD show per-case-type compliance rates
- AND case types below target MUST be highlighted with a warning indicator

#### Scenario DASH-012c: No completed cases for SLA
- GIVEN no cases were completed in the last 30 days
- WHEN the user views the SLA widget
- THEN the system MUST show "No data" or "N/A"
- AND the widget MUST NOT show 0% (which would be misleading)

### REQ-DASH-013: Workload Distribution [V1]

The dashboard SHALL display how cases are distributed across team members to enable workload balancing.

#### Scenario DASH-013a: Workload by handler
- GIVEN open cases assigned as: Jan (8), Maria (6), Pieter (4), Unassigned (6)
- WHEN the user views the workload widget
- THEN the system MUST display a horizontal bar chart showing cases per handler
- AND unassigned cases MUST be shown separately as "Unassigned"
- AND handlers with more than the average load MUST be highlighted

#### Scenario DASH-013b: Workload with overdue breakdown
- GIVEN Jan has 8 cases total, 3 of which are overdue
- WHEN the user views the workload widget
- THEN Jan's bar MUST show a split: 5 normal + 3 overdue (distinct color)
- AND this allows managers to identify overloaded handlers with overdue work

#### Scenario DASH-013c: Workload widget admin only
- GIVEN a non-admin user views the dashboard
- THEN the workload distribution widget SHOULD be hidden or show only the user's own workload
- AND only admin/manager users SHOULD see the full team workload distribution

### REQ-DASH-014: Dashboard Layout [MVP]

The dashboard MUST follow a configurable grid layout using `CnDashboardPage` from `@conduction/nextcloud-vue`.

#### Scenario DASH-014a: Default layout structure
- GIVEN the user views the dashboard for the first time
- THEN the page MUST display the following sections in the default layout:
  1. Header with quick action buttons (New Case, New Task, Refresh)
  2. KPI cards row (4 cards: Open Cases, Overdue, Completed This Month, My Tasks) each spanning 3 grid columns
  3. Two-column layout below the KPI row:
     - Left column (6 cols): Cases by Status chart, My Work preview
     - Right column (6 cols): Overdue Cases panel, Recent Activity feed
- AND the layout MUST be responsive, collapsing to a single column on narrow viewports

#### Scenario DASH-014b: Navigation header
- GIVEN the user is on the dashboard
- THEN the navigation MUST include tabs or links for: Dashboard, Cases, Tasks, Decisions, My Work, and Settings (admin only)
- AND the Dashboard tab MUST be visually marked as active

#### Scenario DASH-014c: Layout persistence
- GIVEN the user rearranges widgets using the grid layout
- WHEN the user returns to the dashboard later
- THEN the system SHOULD persist the custom layout
- AND the system MUST provide a "Reset layout" option to return to defaults

### REQ-DASH-015: Nextcloud Dashboard Widgets [MVP]

The system MUST register three Nextcloud-native dashboard widgets for display on the main Nextcloud dashboard.

#### Scenario DASH-015a: Cases Overview widget
- GIVEN the user has Procest installed
- WHEN the user views the main Nextcloud dashboard
- THEN a "Cases Overview" widget MUST be available for selection
- AND the widget MUST show open cases count and overdue count
- AND clicking the widget MUST navigate to the Procest dashboard

#### Scenario DASH-015b: My Tasks widget
- GIVEN the user has tasks assigned in Procest
- WHEN the My Tasks widget is displayed on the Nextcloud dashboard
- THEN it MUST show the top 5 tasks with title, due date, and parent case reference
- AND clicking a task MUST navigate to the task detail in Procest

#### Scenario DASH-015c: Overdue Cases widget
- GIVEN there are 3 overdue cases
- WHEN the Overdue Cases widget is displayed on the Nextcloud dashboard
- THEN it MUST list overdue cases with title, days overdue, and handler
- AND clicking a case MUST navigate to the case detail in Procest

## Non-Functional Requirements

- **Performance**: Dashboard MUST load within 2 seconds for up to 1000 cases. Individual API calls SHOULD complete within 500ms. Data is fetched using `Promise.allSettled` with a limit of 1000 cases, 100 case types, 500 status types, and 100 tasks.
- **Accessibility**: All KPI cards MUST have appropriate ARIA labels. Charts MUST have text alternatives. The dashboard MUST meet WCAG AA standards. All clickable elements MUST be keyboard-navigable.
- **Localization**: All labels, messages, and date formatting MUST support English and Dutch localization using `t('procest', ...)`.
- **Caching**: Dashboard data MAY be cached client-side for up to 60 seconds to reduce API load, but MUST be refreshable on demand via the refresh button.

### Current Implementation Status

**Substantially implemented (MVP).** The dashboard is fully functional with KPI cards, status chart, My Work preview, and quick actions.

**Implemented:**
- Dashboard page (`src/views/Dashboard.vue`) using `CnDashboardPage` from `@conduction/nextcloud-vue` with configurable grid layout.
- KPI cards row (4 cards): Open Cases (with count), Overdue (with warning styling when > 0), Completed This Month (count), My Tasks (count). Cards use material design icons (FolderOpen, AlertCircle, CheckCircle, ClipboardCheckOutline).
- KPI cards with sub-labels: "+N today", "action needed"/"all on track", "avg N days"/"no data", "N due today"/"none due today". Implemented in `src/views/dashboard/KpiCards.vue`.
- Cases by Status horizontal bar chart with proportional bar widths, status labels, counts, and color coding. Empty state: "No open cases". Implemented in `src/views/dashboard/StatusChart.vue`.
- My Work preview panel showing top 5 items (cases and tasks) with entity type badges ([CASE]/[TASK]), title, reference, deadline text, overdue highlighting. "View all my work" link navigates to MyWork route. Implemented in `src/views/dashboard/MyWorkPreview.vue`.
- Quick actions: "+ New Case" button (primary) and "+ New Task" button in header area. Refresh button with spinning animation.
- Case creation dialog (`CaseCreateDialog`) and Task creation dialog (`TaskCreateDialog`) integrated.
- Dashboard data loading via `Promise.allSettled` for resilient parallel fetching: cases (limit 1000), caseTypes (limit 100), statusTypes (limit 500), tasks (filtered by current user, limit 100).
- KPI computation (`src/utils/dashboardHelpers.js::computeKpis`) calculating open count, overdue count, completed this month count, task count.
- Status aggregation (`src/utils/dashboardHelpers.js::aggregateByStatus`).
- My Work items generation (`src/utils/dashboardHelpers.js::getMyWorkItems`).
- Empty state with welcome message (different for admin vs regular user).
- Error display with retry button.
- Auto-refresh every 5 minutes (`setInterval`).
- Loading state with `globalLoading` flag and `icon-spinning` animation.
- Grid layout with DEFAULT_LAYOUT: 4 KPI tiles (3 cols each) in row 1, cases-by-status (6 cols) and my-work (6 cols) in row 2.
- Navigation to case/task detail on work item click.
- Clickable KPI cards navigating to filtered views (Open Cases -> Cases with status=open, Overdue -> Cases with overdue=true, Completed -> Cases with status=completed, My Tasks -> Tasks view).
- Three Nextcloud Dashboard widgets registered as PHP classes: `CasesOverviewWidget` (`lib/Dashboard/CasesOverviewWidget.php`), `MyTasksWidget` (`lib/Dashboard/MyTasksWidget.php`), `OverdueCasesWidget` (`lib/Dashboard/OverdueCasesWidget.php`).
- Widget entry points: `src/casesOverviewWidget.js`, `src/myTasksWidget.js`, `src/overdueCasesWidget.js`.
- Widget Vue components: `src/views/widgets/CasesOverviewWidget.vue`, `src/views/widgets/MyTasksWidget.vue`, `src/views/widgets/OverdueCasesWidget.vue`.
- Dashboard helper components: `src/views/dashboard/KpiCards.vue`, `src/views/dashboard/StatusChart.vue`, `src/views/dashboard/OverduePanel.vue`, `src/views/dashboard/MyWorkPreview.vue`, `src/views/dashboard/ActivityFeed.vue`.

**Not yet implemented or partial:**
- REQ-DASH-003: Cases by Type chart (V1).
- REQ-DASH-004: Overdue Cases panel as separate panel in the two-column layout (overdue is shown as KPI card count but not as a detailed list panel with case details in the main dashboard -- the `OverduePanel.vue` component exists but may not be wired into the main dashboard layout).
- REQ-DASH-006: Recent Activity feed (the `ActivityFeed.vue` component exists but is not visually present in the `Dashboard.vue` template -- no `#widget-activity` slot).
- REQ-DASH-011: Average Processing Time KPI (V1) -- the `kpis` object has `avgDays` field and the KPI card supports displaying "avg N days" but the actual calculation may not be complete.
- REQ-DASH-012: SLA Compliance widget (V1) -- not implemented.
- REQ-DASH-013: Workload Distribution widget (V1) -- not implemented.
- RBAC scoping -- dashboard fetches all cases (limit 1000) without explicit RBAC filtering (relies on OpenRegister's built-in access control).
- Layout responsiveness (single-column collapse on narrow viewports).

### Standards & References

- **WCAG AA**: KPI cards need ARIA labels, charts need text alternatives.
- **Nextcloud Dashboard API**: Three IWidget implementations for Nextcloud-native dashboard integration.
- **Nextcloud Activity API (`OCP\Activity\IManager`)**: Activity feed data source (mentioned in spec, `ActivityFeed.vue` component exists).
- **GEMMA**: Dashboard follows zaakgericht werken management information patterns.
- **Competitor reference**: Dimpact ZAC provides a dashboard with case counts, overdue warnings, and team workload views. Flowable Platform includes case KPI dashboards with SLA compliance metrics and workload distribution.
