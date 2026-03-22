## ADDED Requirements

### Requirement: Deadline Alerts Widget [V1]

The dashboard SHALL display a Deadline Alerts widget that lists cases approaching their processing deadline and cases already overdue, enabling proactive case management. The widget uses a configurable warning threshold (default: 3 days) to identify "at risk" cases before they become overdue.

#### Scenario: Cases approaching deadline within warning threshold
- **WHEN** there are 2 open cases with deadlines within the next 3 days (warning threshold) and 1 case with deadline in 5 days
- **THEN** the Deadline Alerts widget MUST display the 2 at-risk cases
- **THEN** each case MUST show: title, identifier, case type name, days remaining, and assigned handler
- **THEN** cases MUST be sorted by days remaining ascending (most urgent first)
- **THEN** the case with 5 days remaining MUST NOT appear in the widget

#### Scenario: Overdue cases shown above at-risk cases
- **WHEN** there are 2 overdue cases (3 days overdue, 1 day overdue) and 1 at-risk case (due tomorrow)
- **THEN** the widget MUST display overdue cases in a section above at-risk cases
- **THEN** overdue cases MUST use a red/error severity indicator
- **THEN** at-risk cases MUST use a yellow/warning severity indicator
- **THEN** overdue cases MUST be sorted by days overdue descending (most overdue first)

#### Scenario: No deadline alerts
- **WHEN** all open cases have deadlines more than 3 days away (or no deadline)
- **THEN** the widget MUST display a positive message such as "No deadline alerts"
- **THEN** the widget MUST NOT show an error or empty broken state

#### Scenario: Clicking a deadline alert row navigates to case detail
- **WHEN** the user clicks on a case row in the Deadline Alerts widget
- **THEN** the system MUST navigate to the case detail view for that case

#### Scenario: View all link navigates to filtered case list
- **WHEN** the user clicks "View all deadline alerts"
- **THEN** the system MUST navigate to the Cases view filtered to show overdue and at-risk cases

### Requirement: Task Due Reminders Widget [V1]

The dashboard SHALL display a Task Due Reminders widget showing the current user's tasks that are approaching or past their due date, sorted by urgency.

#### Scenario: Tasks approaching due date
- **WHEN** the current user has 3 tasks with due dates within the next 3 days and 2 tasks due in 7 days
- **THEN** the widget MUST display the 3 urgent tasks
- **THEN** each task MUST show: title, parent case reference, days remaining or "due today", and priority badge
- **THEN** tasks MUST be sorted by due date ascending (most urgent first)

#### Scenario: Overdue tasks shown with error indicator
- **WHEN** the current user has 2 tasks past their due date (2 days overdue, 1 day overdue)
- **THEN** the widget MUST display overdue tasks above upcoming tasks
- **THEN** overdue tasks MUST show "N days overdue" with a red/error visual indicator
- **THEN** overdue tasks MUST be sorted by days overdue descending

#### Scenario: No task reminders
- **WHEN** the current user has no tasks approaching or past their due date within the warning threshold
- **THEN** the widget MUST display a message such as "No task reminders"

#### Scenario: Clicking a task reminder navigates to task detail
- **WHEN** the user clicks on a task in the Task Due Reminders widget
- **THEN** the system MUST navigate to the task detail view

#### Scenario: Tasks without due dates excluded
- **WHEN** a task has no due date set
- **THEN** the task MUST NOT appear in the Task Due Reminders widget

### Requirement: Stalled Cases Widget [V1]

The dashboard SHALL display a Stalled Cases widget identifying cases that have had no status change or activity for a configurable period (default: 7 days), indicating they may need attention.

#### Scenario: Cases with no recent activity
- **WHEN** there are 3 open cases with no status change in the last 7 days and 2 open cases that had a status change 2 days ago
- **THEN** the Stalled Cases widget MUST display the 3 stalled cases
- **THEN** each case MUST show: title, identifier, case type name, days since last activity, and assigned handler
- **THEN** cases MUST be sorted by days since last activity descending (most stalled first)
- **THEN** the 2 recently-active cases MUST NOT appear

#### Scenario: Stalled case detection uses dateModified
- **WHEN** a case object has a `dateModified` field with value 10 days ago
- **THEN** the case MUST be identified as stalled (10 days > 7 day threshold)
- **THEN** the widget MUST show "10 days inactive" for that case

#### Scenario: No stalled cases
- **WHEN** all open cases have had activity within the last 7 days
- **THEN** the widget MUST display a positive message such as "All cases active"

#### Scenario: Clicking a stalled case navigates to case detail
- **WHEN** the user clicks on a case in the Stalled Cases widget
- **THEN** the system MUST navigate to the case detail view for that case

#### Scenario: Final-status cases excluded from stalled detection
- **WHEN** a case has a final status and no activity in 30 days
- **THEN** the case MUST NOT appear in the Stalled Cases widget (completed cases are not stalled)

### Requirement: Nextcloud Dashboard Signalering Widgets [V1]

The system SHALL register Nextcloud-native dashboard widgets (IWidget) for the signalering components so they appear on the main Nextcloud dashboard.

#### Scenario: Deadline Alerts Nextcloud widget available
- **WHEN** the user views the main Nextcloud dashboard widget picker
- **THEN** a "Deadline Alerts" widget MUST be available for selection
- **THEN** the widget MUST show the top 5 at-risk and overdue cases with title, days remaining/overdue, and severity indicator
- **THEN** clicking the widget header MUST navigate to the Procest dashboard

#### Scenario: Task Reminders Nextcloud widget available
- **WHEN** the user views the main Nextcloud dashboard widget picker
- **THEN** a "Task Reminders" widget MUST be available for selection
- **THEN** the widget MUST show the top 5 urgent tasks with title, due date info, and parent case
- **THEN** clicking a task MUST navigate to the task detail in Procest

#### Scenario: Stalled Cases Nextcloud widget available
- **WHEN** the user views the main Nextcloud dashboard widget picker
- **THEN** a "Stalled Cases" widget MUST be available for selection
- **THEN** the widget MUST show the top 5 most stalled cases with title, days inactive, and handler

### Requirement: Signalering Helper Functions [V1]

The system SHALL provide dashboard helper functions for computing deadline proximity, stalled case detection, and alert urgency sorting.

#### Scenario: getDeadlineAlerts returns at-risk and overdue cases
- **WHEN** called with open cases array, case types array, and warning threshold of 3
- **THEN** the function MUST return an object with `overdue` array and `atRisk` array
- **THEN** overdue items MUST have: id, title, identifier, caseTypeName, daysOverdue, handler
- **THEN** atRisk items MUST have: id, title, identifier, caseTypeName, daysRemaining, handler

#### Scenario: getTaskDueReminders returns urgent tasks for current user
- **WHEN** called with user's tasks array and warning threshold of 3
- **THEN** the function MUST return an object with `overdue` array and `dueSoon` array
- **THEN** each item MUST have: id, title, caseReference, daysRemaining or daysOverdue, priority

#### Scenario: getStalledCases returns inactive cases
- **WHEN** called with open cases array, case types array, and stalled threshold of 7
- **THEN** the function MUST return an array of cases with dateModified older than 7 days
- **THEN** each item MUST have: id, title, identifier, caseTypeName, daysSinceActivity, handler

### Requirement: Dashboard Layout Extension for Signalering [V1]

The existing dashboard layout SHALL be extended to include the signalering widgets in a third row below the existing KPI cards, status chart, and My Work preview.

#### Scenario: Default layout includes signalering row
- **WHEN** the user views the dashboard for the first time after this feature is added
- **THEN** the default layout MUST include a third row with:
  - Deadline Alerts widget (4 grid columns)
  - Task Due Reminders widget (4 grid columns)
  - Stalled Cases widget (4 grid columns)
- **THEN** the existing KPI cards row and status/my-work row MUST remain unchanged

#### Scenario: Signalering widgets respect dashboard grid system
- **WHEN** signalering widgets are displayed
- **THEN** they MUST use the CnDashboardPage grid system with proper widgetId and slot names
- **THEN** users MUST be able to rearrange them like other dashboard widgets

## MODIFIED Requirements

### Requirement: Dashboard Layout [MVP]

The dashboard MUST follow a configurable grid layout using `CnDashboardPage` from `@conduction/nextcloud-vue`.

#### Scenario: Default layout structure
- GIVEN the user views the dashboard for the first time
- THEN the page MUST display the following sections in the default layout:
  1. Header with quick action buttons (New Case, New Task, Refresh)
  2. KPI cards row (4 cards: Open Cases, Overdue, Completed This Month, My Tasks) each spanning 3 grid columns
  3. Two-column layout below the KPI row:
     - Left column (6 cols): Cases by Status chart, My Work preview
     - Right column (6 cols): Overdue Cases panel, Recent Activity feed
  4. Signalering row (3 equal columns, 4 cols each): Deadline Alerts, Task Due Reminders, Stalled Cases
- AND the layout MUST be responsive, collapsing to a single column on narrow viewports
