# Delta: dashboard

## Purpose

Implement the V1 tier of the dashboard spec (`openspec/specs/dashboard/spec.md`), the signalering-widgets spec (`openspec/specs/signalering-widgets/spec.md`), and the analytics subset of the doorlooptijd-dashboard spec. Adds Cases by Type chart, three signalering widgets, a Woo deadline panel, a process analytics view, and a workflow tracking board. Fixes Application.php widget registration.

## ADDED Requirements

### Requirement: REQ-DASH-003 Cases by Type Chart [V1]

The dashboard SHALL render `CaseTypeChart.vue`: a horizontal CSS bar chart of open cases grouped by case type, sorted by count descending. Clicking a bar MUST navigate to the Cases view filtered by that case type. It follows the same CSS bar chart pattern as the Cases by Status chart.

#### Scenario DASH-003a: Cases distributed across multiple case types
- GIVEN open cases distributed as: Omgevingsvergunning (10), Subsidieaanvraag (7), Klacht (4), Melding (3)
- WHEN the user views the main dashboard
- THEN the system MUST display a horizontal bar chart titled "Cases by Type"
- AND each bar MUST show the case type title on the left and the count on the right
- AND bars MUST be sorted by count descending (most cases first)
- AND each bar's width MUST be proportional to its count relative to the maximum count

#### Scenario DASH-003b: Click bar navigates to filtered case list
- GIVEN the Cases by Type chart is visible
- WHEN the user clicks on the bar labelled "Omgevingsvergunning"
- THEN the system MUST navigate to the Cases view with a `caseType` filter applied to "Omgevingsvergunning"

#### Scenario DASH-003c: No open cases
- GIVEN no open cases exist
- WHEN the user views the Cases by Type chart
- THEN the chart MUST display a message "No open cases" rather than an empty chart area

### Requirement: REQ-DASH-010 Doorlooptijd Navigation Link [V1]

The dashboard SHALL provide an "Analytics" navigation item in the left sidebar (MainMenu.vue) pointing to `/dashboard/analytics`. The main dashboard header MUST also display a "View Analytics" link that navigates to the same route.

#### Scenario DASH-010a: Analytics navigation from main dashboard
- GIVEN the user is on the main dashboard
- WHEN they click the "View Analytics" link or the sidebar "Analytics" item
- THEN the system MUST navigate to the `/dashboard/analytics` route

### Requirement: REQ-DASH-V1-001 Signalering: Deadline Alerts Widget [V1]

`DeadlineAlertsWidget.vue` SHALL display cases approaching or past their processing deadline. The warning threshold MUST be 3 days (configurable via `IAppConfig` in V2). It integrates `getDeadlineAlerts()` from `signaleringHelpers.js`.

#### Scenario DASH-V1-001a: Cases approaching deadline within warning threshold
- GIVEN 2 open cases with deadlines within the next 3 days and 1 case with deadline in 5 days
- WHEN the user views the dashboard Signalering section
- THEN the Deadline Alerts widget MUST display the 2 at-risk cases
- AND each case row MUST show: case identifier, title, case type name, days remaining
- AND cases MUST be sorted by days remaining ascending (most urgent first)
- AND the case with 5 days remaining MUST NOT appear in the widget

#### Scenario DASH-V1-001b: Overdue cases displayed above at-risk cases
- GIVEN 2 overdue cases (3 days overdue, 1 day overdue) and 1 at-risk case (due tomorrow)
- WHEN the user views the Deadline Alerts widget
- THEN overdue cases MUST appear in a section above at-risk cases
- AND overdue cases MUST use a red/error severity indicator (`--color-error`)
- AND at-risk cases MUST use a yellow/warning severity indicator (`--color-warning`)
- AND both severity levels MUST be communicated by both color AND text label (not color alone)
- AND overdue cases MUST be sorted by days overdue descending

#### Scenario DASH-V1-001c: No deadline alerts
- GIVEN all open cases have deadlines more than 3 days away or have no deadline
- WHEN the user views the Deadline Alerts widget
- THEN the widget MUST display the message "No deadline alerts"
- AND the widget MUST NOT show an error state or broken layout

#### Scenario DASH-V1-001d: Click on alert row navigates to case detail
- GIVEN the Deadline Alerts widget is showing cases
- WHEN the user clicks a case row
- THEN the system MUST navigate to the case detail view for that case

### Requirement: REQ-DASH-V1-002 Signalering: Task Due Reminders Widget [V1]

`TaskDueRemindersWidget.vue` SHALL show the current user's tasks with due dates within 3 days or past due. It integrates `getTaskReminders()` from `signaleringHelpers.js`. Tasks without a `dueDate` MUST be excluded.

#### Scenario DASH-V1-002a: Tasks approaching due date
- GIVEN the current user has 3 tasks with due dates within the next 3 days and 2 tasks due in 7 days
- WHEN the user views the Task Due Reminders widget
- THEN the widget MUST display the 3 urgent tasks
- AND each task row MUST show: title, parent case reference, days remaining or "Due today", priority badge
- AND tasks MUST be sorted by due date ascending

#### Scenario DASH-V1-002b: Overdue tasks shown with error indicator
- GIVEN the current user has 2 tasks past their due date (2 days overdue, 1 day overdue)
- WHEN the user views the Task Due Reminders widget
- THEN overdue tasks MUST appear above upcoming tasks
- AND overdue tasks MUST display "N days overdue" with a red/error visual indicator

#### Scenario DASH-V1-002c: Tasks without due dates excluded
- GIVEN a task assigned to the current user has no `dueDate` set
- WHEN the system computes task reminders
- THEN that task MUST NOT appear in the Task Due Reminders widget

#### Scenario DASH-V1-002d: No task reminders
- GIVEN the current user has no tasks with due dates within the warning threshold
- WHEN the user views the Task Due Reminders widget
- THEN the widget MUST display "No task reminders"

### Requirement: REQ-DASH-V1-003 Signalering: Stalled Cases Widget [V1]

`StalledCasesWidget.vue` SHALL identify open cases with no `updatedAt` change in 7+ calendar days. It integrates `getStalledCases()` from `signaleringHelpers.js`.

#### Scenario DASH-V1-003a: Stalled cases identified by inactivity
- GIVEN an open case "Melding overlast" last updated 10 days ago
- AND an open case "Bezwaar omgevingsvergunning" last updated 3 days ago
- WHEN the user views the Stalled Cases widget (threshold: 7 days)
- THEN the widget MUST display "Melding overlast" with "10 days without update"
- AND "Bezwaar omgevingsvergunning" MUST NOT appear in the widget
- AND cases MUST be sorted by days since last update descending

#### Scenario DASH-V1-003b: Click stalled case navigates to detail
- GIVEN a stalled case is displayed
- WHEN the user clicks it
- THEN the system MUST navigate to the case detail view

#### Scenario DASH-V1-003c: No stalled cases
- GIVEN all open cases were updated within the last 7 days
- WHEN the user views the Stalled Cases widget
- THEN the widget MUST display "No stalled cases"

### Requirement: REQ-DASH-V1-004 Woo Deadline Tracking Panel [V1]

`WooDeadlinePanel.vue` SHALL list open cases whose case type title contains "Woo" (case-insensitive). It MUST display a statutory deadline countdown with traffic-light severity. Woo responses are due within 4 weeks (28 days) with a single 2-week (14-day) extension. It integrates `getWooCases()` from `signaleringHelpers.js`.

#### Scenario DASH-V1-004a: Woo cases shown with countdown
- GIVEN 3 open cases with a case type named "Woo-verzoek"
- AND case A has 5 days remaining, case B has 15 days remaining, case C has 2 days remaining
- WHEN the user views the Woo Deadline panel
- THEN all 3 cases MUST appear sorted by days remaining ascending
- AND case C (2 days) MUST show severity "critical" (orange indicator)
- AND case A (5 days) MUST show severity "warning" (yellow indicator)
- AND case B (15 days) MUST show severity "ok" (green indicator)
- AND each row MUST show: identifier, title, initiator name (if available), days remaining

#### Scenario DASH-V1-004b: Overdue Woo case shown with error severity
- GIVEN a Woo case whose deadline passed 2 days ago
- WHEN the user views the Woo Deadline panel
- THEN the case MUST show severity "overdue" with a red/error indicator
- AND the row MUST show "2 days overdue" text

#### Scenario DASH-V1-004c: No Woo cases
- GIVEN no open cases have a Woo case type
- WHEN the panel loads
- THEN the panel MUST display "No open Woo requests" and NOT show an error

#### Scenario DASH-V1-004d: Click navigates to case detail
- GIVEN a Woo case row is displayed
- WHEN the user clicks the row
- THEN the system MUST navigate to the case detail view for that case

### Requirement: REQ-DASH-V1-005 Process Analytics View [V1]

`ProcessAnalytics.vue` SHALL provide SLA compliance analytics at `/dashboard/analytics`. It MUST implement the SLA compliance and throughput requirements from `openspec/specs/doorlooptijd-dashboard/spec.md`.

#### Scenario DASH-V1-005a: SLA compliance rate KPI
- GIVEN 82 out of 100 completed cases in the selected date range finished within their case type's `processingDeadline`
- WHEN the user views the Process Analytics page
- THEN the system MUST display an SLA compliance rate of "82%"
- AND the sub-label MUST display "82 / 100 within SLA"
- AND cases without a SLA target MUST be excluded from the calculation
- AND the system MUST show a note "N cases excluded — no SLA target" when any are excluded

#### Scenario DASH-V1-005b: SLA compliance breakdown table
- GIVEN case type "Vergunning" has 40 completed cases (35 within SLA) and "Bezwaar" has 30 (20 within SLA)
- WHEN the user views the Process Analytics page
- THEN a table MUST show each case type with: name, total completed, within-SLA count, within-SLA %, avg actual days, SLA target days
- AND a donut chart MUST show the proportion of within-SLA vs outside-SLA for each case type

#### Scenario DASH-V1-005c: Throughput chart — completed cases per week
- GIVEN 80 cases were completed over the trailing 12 weeks
- WHEN the user views the Process Analytics page
- THEN a line chart MUST display cases closed per week for those 12 weeks
- AND the X-axis MUST label each week (e.g., "Week 15", "Week 16")
- AND the chart MUST use `CnChartWidget` from `@conduction/nextcloud-vue`

#### Scenario DASH-V1-005d: Date range filter
- GIVEN the user selects a date range of "last 6 months" from the filter
- WHEN the analytics data is re-fetched
- THEN the SLA KPI, breakdown table, and throughput chart MUST all update to reflect the selected range

#### Scenario DASH-V1-005e: No completed cases in range
- GIVEN no cases completed in the selected date range
- WHEN the user views the Process Analytics page
- THEN the SLA KPI MUST display "No data" (not "0%")
- AND the throughput chart MUST show an empty state message

### Requirement: REQ-DASH-V1-006 Workflow Board View [V1]

`WorkflowBoard.vue` at `/workflow-board` SHALL provide a Kanban board with one column per non-final status type, case cards in each column, and drag-to-advance status transition.

#### Scenario DASH-V1-006a: Board columns reflect status types
- GIVEN status types: Ontvangen (order 1), In behandeling (order 2), Besluitvorming (order 3), each non-final
- WHEN the user views the Workflow Board
- THEN the board MUST display 3 columns in order: Ontvangen, In behandeling, Besluitvorming
- AND each column header MUST show the status name and the count of cases in that status

#### Scenario DASH-V1-006b: Case cards show key information
- GIVEN case "2026-0042 Omgevingsvergunning - Bakkersdijk 12" in status "In behandeling"
- WHEN the user views the Workflow Board
- THEN the case card MUST show: case identifier, title (truncated if necessary), case type badge, assignee name, and deadline with color indicator

#### Scenario DASH-V1-006c: Drag to advance case status
- GIVEN case "2026-0042" is in the "Ontvangen" column
- WHEN the user drags the card to the "In behandeling" column and drops it
- THEN the system MUST update the case's `status` to the "In behandeling" statusType ID
- AND the card MUST move to the "In behandeling" column
- AND if the update fails (e.g., permission denied), the card MUST return to its original column
- AND a user-facing error message MUST be displayed on failure

#### Scenario DASH-V1-006d: Click on case card navigates to detail
- GIVEN a case card is visible on the board
- WHEN the user clicks the card (not drags it)
- THEN the system MUST navigate to the case detail view for that case

#### Scenario DASH-V1-006e: Empty column
- GIVEN no cases are currently in the "Besluitvorming" status
- WHEN the user views the Workflow Board
- THEN the "Besluitvorming" column MUST still be displayed with count "0"
- AND the column body MUST show an empty state placeholder

### Requirement: REQ-DASH-FIX-001 Application.php Widget Registration [FIX]

The system SHALL register the three existing Nextcloud Dashboard widget classes (`CasesOverviewWidget`, `MyTasksWidget`, `OverdueCasesWidget`) in `Application.php` via `$context->registerDashboardWidget()`. It MUST fix `CasesOverviewWidget`'s route reference from the non-existent `.dashboard.index` to the correct `.dashboard.page`.

#### Scenario DASH-FIX-001a: Widgets appear in Nextcloud Dashboard
- GIVEN the user navigates to the Nextcloud Dashboard (`/apps/dashboard`)
- WHEN widgets have been registered in Application.php
- THEN the Procest widgets (Cases Overview, My Tasks, Overdue Cases) MUST be available for the user to add
- AND the widgets MUST load without routing errors

#### Scenario DASH-FIX-001b: CasesOverviewWidget deep link resolves correctly
- GIVEN the user has added the Cases Overview widget to their Nextcloud Dashboard
- WHEN the widget renders and the user clicks a link in it
- THEN the system MUST navigate to the correct Procest route (`.dashboard.page`)
- AND a 404 or broken navigation MUST NOT occur
