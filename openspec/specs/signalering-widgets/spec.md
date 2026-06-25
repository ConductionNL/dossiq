---
status: done
retrofit_extensions:
  - REQ-001
  - REQ-002
  - REQ-003
---

# Signalering Widgets Specification

## Purpose

The signalering (alerting) widgets provide proactive deadline awareness on the Procest dashboard. They surface time-sensitive alerts so case handlers can act before deadlines pass: cases approaching their processing deadline, tasks approaching their due date, and cases that have gone stagnant with no recent activity.

**Feature tier**: V1

## Data Sources

All signalering widget data is computed client-side from the same OpenRegister collections already fetched by the main dashboard:
- **Cases**: schema `case` — filtered by non-final status, deadline proximity, and dateModified recency
- **Tasks**: schema `task` — filtered by current user assignment and due date proximity
- **Case Types**: schema `caseType` — for case type name resolution

## Requirements

### Requirement: Deadline Alerts Widget [V1]

The dashboard SHALL display a Deadline Alerts widget that lists cases approaching their processing deadline and cases already overdue, enabling proactive case management. The widget uses a configurable warning threshold (default: 3 days) to identify "at risk" cases before they become overdue. The widget RENDERS as a titled card ("Deadline Alerts") in the in-app dashboard grid with a data-independent empty-state — these UI surfaces are browser-verifiable; the data-row/sort/navigation behaviours require pre-seeded cases with specific dates and stay excluded per-scenario.

#### Scenario: Cases approaching deadline within warning threshold
@e2e exclude Requires 2+ cases with deadlines within 3 days plus sort assertions; data-dependent, not testable without pre-seeded cases with specific dates.
- **WHEN** there are 2 open cases with deadlines within the next 3 days (warning threshold) and 1 case with deadline in 5 days
- **THEN** the Deadline Alerts widget MUST display the 2 at-risk cases
- **THEN** each case MUST show: title, identifier, case type name, days remaining, and assigned handler
- **THEN** cases MUST be sorted by days remaining ascending (most urgent first)
- **THEN** the case with 5 days remaining MUST NOT appear in the widget

#### Scenario: Overdue cases shown above at-risk cases
@e2e exclude Requires seeded overdue + at-risk cases and section-ordering/severity assertions; data-dependent, not testable without pre-seeded cases with specific dates.
- **WHEN** there are 2 overdue cases (3 days overdue, 1 day overdue) and 1 at-risk case (due tomorrow)
- **THEN** the widget MUST display overdue cases in a section above at-risk cases
- **THEN** overdue cases MUST use a red/error severity indicator
- **THEN** at-risk cases MUST use a yellow/warning severity indicator
- **THEN** overdue cases MUST be sorted by days overdue descending (most overdue first)

#### Scenario: No deadline alerts
@e2e exclude The "No deadline alerts" empty message lives inside DeadlineAlertsWidget.vue, which only mounts on the Nextcloud SYSTEM dashboard (OCA.Dashboard.register) after the user adds it via the widget picker; the in-app dashboard grid renders only the titled placeholder card (covered by default-layout-includes-signalering-row), so the widget-internal empty-state is not reachable as an in-app browser surface.
- **WHEN** all open cases have deadlines more than 3 days away (or no deadline)
- **THEN** the widget MUST display a positive message such as "No deadline alerts"
- **THEN** the widget MUST NOT show an error or empty broken state

#### Scenario: Clicking a deadline alert row navigates to case detail
@e2e exclude Requires a seeded at-risk/overdue case row to click; navigation-on-data-row is data-dependent, not testable without pre-seeded cases.
- **WHEN** the user clicks on a case row in the Deadline Alerts widget
- **THEN** the system MUST navigate to the case detail view for that case

#### Scenario: View all link navigates to filtered case list
@e2e exclude The "View all" link only renders when alerts are present (data-dependent) and asserts a filtered cross-view navigation; not testable without pre-seeded cases.
- **WHEN** the user clicks "View all deadline alerts"
- **THEN** the system MUST navigate to the Cases view filtered to show overdue and at-risk cases

### Requirement: Task Due Reminders Widget [V1]

The dashboard SHALL display a Task Due Reminders widget showing the current user's tasks that are approaching or past their due date, sorted by urgency. The widget RENDERS as a titled card ("Task Due Reminders") in the in-app dashboard grid with a data-independent empty-state — these UI surfaces are browser-verifiable; the data-row/sort/navigation behaviours require pre-seeded tasks and stay excluded per-scenario.

#### Scenario: Tasks approaching due date
@e2e exclude Requires 3+ tasks with due dates within threshold plus sort assertions; data-dependent, not testable without pre-seeded tasks.
- **WHEN** the current user has 3 tasks with due dates within the next 3 days and 2 tasks due in 7 days
- **THEN** the widget MUST display the 3 urgent tasks
- **THEN** each task MUST show: title, parent case reference, days remaining or "due today", and priority badge
- **THEN** tasks MUST be sorted by due date ascending (most urgent first)

#### Scenario: Overdue tasks shown with error indicator
@e2e exclude Requires seeded overdue tasks and ordering/severity assertions; data-dependent, not testable without pre-seeded tasks.
- **WHEN** the current user has 2 tasks past their due date (2 days overdue, 1 day overdue)
- **THEN** the widget MUST display overdue tasks above upcoming tasks
- **THEN** overdue tasks MUST show "N days overdue" with a red/error visual indicator
- **THEN** overdue tasks MUST be sorted by days overdue descending

#### Scenario: No task reminders
@e2e exclude The "No task reminders" empty message lives inside TaskRemindersWidget.vue, which only mounts on the Nextcloud SYSTEM dashboard (OCA.Dashboard.register) after the user adds it via the widget picker; the in-app dashboard grid renders only the titled placeholder card (covered by default-layout-includes-signalering-row), so the widget-internal empty-state is not reachable as an in-app browser surface.
- **WHEN** the current user has no tasks approaching or past their due date within the warning threshold
- **THEN** the widget MUST display a message such as "No task reminders"

#### Scenario: Clicking a task reminder navigates to task detail
@e2e exclude Requires a seeded task row to click; navigation-on-data-row is data-dependent, not testable without pre-seeded tasks.
- **WHEN** the user clicks on a task in the Task Due Reminders widget
- **THEN** the system MUST navigate to the task detail view

#### Scenario: Tasks without due dates excluded
@e2e exclude Filtering logic on a seeded task without a due date; backend/helper filter behaviour, covered by dashboardHelpers unit tests, not browser-verifiable.
- **WHEN** a task has no due date set
- **THEN** the task MUST NOT appear in the Task Due Reminders widget

### Requirement: Stalled Cases Widget [V1]

The dashboard SHALL display a Stalled Cases widget identifying cases that have had no status change or activity for a configurable period (default: 7 days), indicating they may need attention. The widget RENDERS as a titled card ("Stalled Cases") in the in-app dashboard grid with a data-independent empty-state ("All cases active") — these UI surfaces are browser-verifiable; the stall-detection/sort/navigation behaviours require time-controlled case data and stay excluded per-scenario.

#### Scenario: Cases with no recent activity
@e2e exclude Requires 3+ cases with dateModified older than 7 days plus sort assertions; data-dependent, not testable without time-controlled case data.
- **WHEN** there are 3 open cases with no status change in the last 7 days and 2 open cases that had a status change 2 days ago
- **THEN** the Stalled Cases widget MUST display the 3 stalled cases
- **THEN** each case MUST show: title, identifier, case type name, days since last activity, and assigned handler
- **THEN** cases MUST be sorted by days since last activity descending (most stalled first)
- **THEN** the 2 recently-active cases MUST NOT appear

#### Scenario: Stalled case detection uses dateModified
@e2e exclude dateModified-based stall detection threshold logic; backend/helper computation, covered by dashboardHelpers unit tests, not browser-verifiable.
- **WHEN** a case object has a `dateModified` field with value 10 days ago
- **THEN** the case MUST be identified as stalled (10 days > 7 day threshold)
- **THEN** the widget MUST show "10 days inactive" for that case

#### Scenario: No stalled cases
@e2e exclude The "All cases active" empty message lives inside StalledCasesWidget.vue, which only mounts on the Nextcloud SYSTEM dashboard (OCA.Dashboard.register) after the user adds it via the widget picker; the in-app dashboard grid renders only the titled placeholder card (covered by default-layout-includes-signalering-row), so the widget-internal empty-state is not reachable as an in-app browser surface.
- **WHEN** all open cases have had activity within the last 7 days
- **THEN** the widget MUST display a positive message such as "All cases active"

#### Scenario: Clicking a stalled case navigates to case detail
@e2e exclude Requires a seeded stalled-case row to click; navigation-on-data-row is data-dependent, not testable without time-controlled case data.
- **WHEN** the user clicks on a case in the Stalled Cases widget
- **THEN** the system MUST navigate to the case detail view for that case

#### Scenario: Final-status cases excluded from stalled detection
@e2e exclude Final-status filtering of a seeded completed case; backend/helper filter behaviour, covered by dashboardHelpers unit tests, not browser-verifiable.
- **WHEN** a case has a final status and no activity in 30 days
- **THEN** the case MUST NOT appear in the Stalled Cases widget (completed cases are not stalled)

### Requirement: Nextcloud Dashboard Signalering Widgets [V1]

The system SHALL register Nextcloud-native dashboard widgets (IWidget) for the signalering components so they appear on the main Nextcloud dashboard.

@e2e exclude NC dashboard widget picker requires opening the Nextcloud system dashboard widget picker; NC IWidget registration is covered by PHPUnit, not Playwright.

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

@e2e exclude Dashboard helper functions (getDeadlineAlerts, getTaskDueReminders, getStalledCases) are pure JS utility functions; covered by dashboardHelpers.js unit tests, not browser Playwright.

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

The existing dashboard layout SHALL be extended to include the signalering widgets in a third row below the existing KPI cards, status chart, and My Work preview. The in-app Dashboard page (manifest `pages[0]`) renders the three signalering widgets as titled cards ("Deadline Alerts", "Task Due Reminders", "Stalled Cases") in its widget grid — this default-layout rendering is browser-verifiable.

#### Scenario: Default layout includes signalering row
- **WHEN** the user views the dashboard for the first time after this feature is added
- **THEN** the default layout MUST include a third row with:
  - Deadline Alerts widget (4 grid columns)
  - Task Due Reminders widget (4 grid columns)
  - Stalled Cases widget (4 grid columns)
- **THEN** the existing KPI cards row and status/my-work row MUST remain unchanged

#### Scenario: Signalering widgets respect dashboard grid system
@e2e exclude Grid-column sizing and drag-rearrange persistence are CSS-grid/layout-store internals, not browser-assertable rendering surfaces.
- **WHEN** signalering widgets are displayed
- **THEN** they MUST use the CnDashboardPage grid system with proper widgetId and slot names
- **THEN** users MUST be able to rearrange them like other dashboard widgets

<!-- BEGIN retrofit-2026-05-24-signalering-widgets -->

### REQ-001: Each signalering widget SHALL implement Nextcloud IWidget with id/title/order/icon/url metadata

Each signalering widget SHALL implement Nextcloud IWidget with id/title/order/icon/url metadata.

@e2e exclude PHP IWidget getId() method return value; widget metadata is covered by PHPUnit, not Playwright.

Each of `DeadlineAlertsWidget`, `OverdueCasesWidget`, `StalledCasesWidget`, and `TaskRemindersWidget` SHALL implement `OCP\Dashboard\IWidget` and expose the canonical metadata via `getId()`, `getTitle()`, `getOrder()`, `getIconClass()`, and `getUrl()`. The widget id SHALL be a stable kebab-case identifier (`procest-deadline-alerts`, `procest-overdue-cases`, `procest-stalled-cases`, `procest-task-reminders`) used by both the Nextcloud dashboard registry and the in-app `widgetDefs` map. The title SHALL be returned through `IL10N::t()` so it follows the user locale.

#### Scenario: Widget id is stable for layout persistence
- **WHEN** `DeadlineAlertsWidget::getId()` is called
- **THEN** it SHALL return `'procest-deadline-alerts'` (verbatim, not derived from class name)
- **AND** the same id SHALL be used in `DEFAULT_LAYOUT` row 3 so saved user layouts stay valid across deployments

### REQ-002: Each signalering widget SHALL register its frontend bundle via load()

Each signalering widget SHALL register its frontend bundle via `load()`.

@e2e exclude PHP IWidget::load() bundle registration; covered by PHPUnit and smoke test verifying script tags, not Playwright.

`IWidget::load()` SHALL call `Util::addScript('procest', '<widget>-main')` and `Util::addStyle('procest', '<widget>-main')` for the widget's compiled Vue bundle. The widget SHALL NOT compute its data payload server-side in PHP — the Vue component fetches data via the Procest API after mounting. The PHP class exists solely to register the widget with Nextcloud's dashboard system and load the frontend bundle.

#### Scenario: Widget bundle is loaded when dashboard is opened
- **WHEN** a user opens the Nextcloud Dashboard with the Deadline Alerts widget in their layout
- **THEN** `DeadlineAlertsWidget::load()` SHALL run and the `deadlineAlertsWidget-main.js` + `.css` bundles SHALL be added to the page

### REQ-003: All four signalering widgets SHALL be registered at app boot via Application

All four signalering widgets SHALL be registered at app boot via `Application`.

@e2e exclude NC Application::register() PHP bootstrap; widget picker registration covered by PHPUnit.

`OCA\Procest\AppInfo\Application::register()` SHALL register all four signalering widgets with the Nextcloud dashboard container so they appear in the dashboard widget picker. Registration order in `Application` SHALL match the canonical `getOrder()` return values to avoid layout drift between dashboard picker and saved user layouts.

#### Scenario: Widgets appear in the dashboard picker
- **WHEN** an authenticated user opens the dashboard widget picker
- **THEN** all four signalering widgets SHALL be listed and clickable
- **AND** each widget's icon + title SHALL match its `getIconClass()` + `getTitle()` return values

<!-- END retrofit-2026-05-24-signalering-widgets -->

## Work Queue Page Render (UI surface)

### REQ-SIG-UI-01: The Work Queue page SHALL render its KPI shell and filters

The Work Queue page (`Werkvoorraad.vue`, route `/werkvoorraad`) SHALL mount and
render its stable shell — the "Work Queue" page heading, the KPI strip
(Open Cases, Overdue, Completed This Week, Unassigned), and the queue filter
buttons (All, Unassigned, Overdue) — independently of whether cases are present.
KPI values and queue rows are data-dependent and covered by the widget data
scenarios above; this scenario asserts only the browser-verifiable rendered shell
and filter controls.

#### Scenario: Work Queue page renders KPI strip and filters
- **GIVEN** an authenticated user on the Procest app
- **WHEN** they navigate to the Work Queue page
- **THEN** the main content MUST show a level-2 "Work Queue" heading
- **AND** the KPI strip MUST show "Open Cases", "Overdue", "Completed This Week" and "Unassigned" labels
- **AND** the "All", "Unassigned" and "Overdue" queue filter buttons MUST be visible

## Non-Functional Requirements

- **Performance**: Signalering computation adds O(n) iteration over already-loaded cases/tasks. No additional API calls required.
- **Accessibility**: All widget rows MUST be keyboard-navigable. Severity indicators MUST use both color and text for colorblind accessibility.
- **Localization**: All labels MUST support English and Dutch using `t('procest', ...)`.
- **Thresholds**: Default warning threshold is 3 days (deadline alerts, task reminders) and 7 days (stalled cases). Defined as named constants for future configurability.

### Current Implementation Status

**Fully implemented (V1).**

**Implemented:**
- Helper functions: `getDeadlineAlerts()`, `getTaskDueReminders()`, `getStalledCases()` in `src/utils/dashboardHelpers.js`
- Threshold constants: `DEADLINE_WARNING_DAYS = 3`, `STALLED_THRESHOLD_DAYS = 7`
- Dashboard components: `src/views/dashboard/DeadlineAlerts.vue`, `src/views/dashboard/TaskDueReminders.vue`, `src/views/dashboard/StalledCases.vue`
- Dashboard integration: widgets registered in `widgetDefs`, layout in `DEFAULT_LAYOUT` row 3, data computed in `loadDashboardData()`
- Nextcloud IWidget classes: `lib/Dashboard/DeadlineAlertsWidget.php`, `lib/Dashboard/TaskRemindersWidget.php`, `lib/Dashboard/StalledCasesWidget.php`
- Nextcloud widget Vue components: `src/views/widgets/DeadlineAlertsWidget.vue`, `src/views/widgets/TaskRemindersWidget.vue`, `src/views/widgets/StalledCasesWidget.vue`
- Webpack entry points: `src/deadlineAlertsWidget.js`, `src/taskRemindersWidget.js`, `src/stalledCasesWidget.js`
- Widget registration in `lib/AppInfo/Application.php` via `registerDashboardWidget()`
