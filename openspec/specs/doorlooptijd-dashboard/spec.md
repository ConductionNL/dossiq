# Doorlooptijd Dashboard Specification

## Purpose

The doorlooptijd (processing time) dashboard provides SLA adherence analytics for case management. It enables team leads and case managers to monitor processing time compliance by case type, identify at-risk cases, and track compliance trends over time. All data is derived from existing case and case type fields in OpenRegister.

**Feature tier**: V1

**ZGW mapping**: Doorlooptijd corresponds to `Zaak.uiterlijkeEinddatumAfdoening` (deadline) and `ZaakType.doorlooptijd` (processing deadline).

## Data Sources

- **Cases**: OpenRegister schema `case` — fields: `startDate`, `endDate`, `deadline`, `plannedEndDate`, `status`, `caseType`
- **Case Types**: OpenRegister schema `caseType` — field: `processingDeadline` (ISO 8601 duration, e.g., `P30D`)
- **Status Types**: OpenRegister schema `statusType` — field: `isFinal` (boolean)

## Requirements

### Requirement: SLA compliance rate widget [V1]

The doorlooptijd dashboard SHALL display an overall SLA compliance rate as a prominent KPI, showing the percentage of completed cases that finished within their case type's `processingDeadline`.

@e2e exclude SLA compliance calculation requires completed cases with processingDeadline set; V1 data-dependent computation scenarios not testable without pre-seeded case data.

#### Scenario: Overall compliance rate calculation
- **WHEN** the user views the doorlooptijd dashboard
- **AND** there are 100 completed cases in the selected date range
- **AND** 82 of those cases had actual processing time (endDate - startDate) less than or equal to their case type's processingDeadline
- **THEN** the system MUST display an SLA compliance rate of "82%"
- **AND** the system MUST display the count "82 / 100 within SLA"

#### Scenario: No completed cases in range
- **WHEN** the user views the doorlooptijd dashboard
- **AND** there are zero completed cases in the selected date range
- **THEN** the system MUST display "No data" instead of a percentage
- **AND** the system MUST NOT show 0% or error states

#### Scenario: Cases without SLA target
- **WHEN** a completed case's case type has no `processingDeadline` set
- **THEN** the system MUST exclude that case from SLA compliance calculations
- **AND** the system MUST display a note showing how many cases were excluded (e.g., "5 cases excluded — no SLA target")

### Requirement: SLA compliance breakdown by case type [V1]

The doorlooptijd dashboard SHALL display a breakdown of SLA compliance per case type, allowing users to identify which case types have the best and worst compliance.

@e2e exclude Compliance breakdown by case type requires completed cases with specific case types and processingDeadline; V1 data-dependent chart/table scenarios not testable without pre-seeded data.

#### Scenario: Compliance by case type with donut chart
- **WHEN** the user views the doorlooptijd dashboard
- **AND** case type "Vergunning" has 40 completed cases, 35 within SLA
- **AND** case type "Bezwaar" has 30 completed cases, 20 within SLA
- **THEN** the system MUST display a table showing each case type with:
  - Case type name
  - Total completed count
  - Within-SLA count and percentage
  - Average actual processing days
  - Target processing days (from `processingDeadline`)
- **AND** the system MUST display a donut chart showing the proportion of within-SLA vs overdue cases per case type

#### Scenario: Case type with zero completed cases
- **WHEN** a case type has zero completed cases in the selected date range
- **THEN** the system MUST show that case type in the table with "—" for compliance metrics
- **AND** the system MUST NOT include it in the donut chart

### Requirement: Processing time distribution chart [V1]

The doorlooptijd dashboard SHALL display a histogram showing the distribution of actual processing times for completed cases.

@e2e exclude Processing time histogram requires completed cases with varying durations; V1 data-dependent chart scenarios not testable without pre-seeded cases.

#### Scenario: Distribution histogram with SLA line
- **WHEN** the user views the doorlooptijd dashboard
- **AND** there are completed cases with varying processing times
- **THEN** the system MUST display a bar chart (histogram) with processing time in days on the X-axis and case count on the Y-axis
- **AND** the system MUST group processing times into bins (e.g., 0-7 days, 8-14 days, 15-21 days, 22-28 days, 29-42 days, 43-56 days, 57+ days)
- **AND** when a single case type is selected in the filter, the system MUST show a vertical reference line at the SLA target days

#### Scenario: Filter by case type
- **WHEN** the user selects a specific case type from the filter dropdown
- **THEN** the histogram MUST update to show only cases of that type
- **AND** the SLA target line MUST appear at the case type's `processingDeadline` in days

### Requirement: Monthly SLA trend chart [V1]

The doorlooptijd dashboard SHALL display a line chart showing the monthly SLA compliance rate over the selected period, enabling trend analysis.

@e2e exclude Monthly SLA trend requires 12 months of completed case data; V1 data-dependent line chart scenarios not testable without time-series data.

#### Scenario: 12-month trend line
- **WHEN** the user views the doorlooptijd dashboard with default date range (last 12 months)
- **AND** each month has varying SLA compliance rates
- **THEN** the system MUST display a line chart with months on the X-axis and compliance percentage on the Y-axis
- **AND** each data point MUST show the compliance rate for cases completed in that month
- **AND** the system MUST display a horizontal reference line at 100% for the SLA target

#### Scenario: Month with no completed cases
- **WHEN** a month in the selected range has zero completed cases
- **THEN** the system MUST show that month with no data point (gap in the line)
- **AND** the system MUST NOT show 0% for that month

#### Scenario: Filter trend by case type
- **WHEN** the user selects a specific case type from the filter dropdown
- **THEN** the trend line MUST update to show compliance rates only for that case type

### Requirement: At-risk cases panel [V1]

The doorlooptijd dashboard SHALL display a panel listing open cases that are at risk of exceeding their SLA deadline, defined as having less than 25% of the allowed processing time remaining.

@e2e exclude At-risk cases panel requires open cases within 25% of deadline; V1 data-dependent panel scenarios not testable without time-controlled case data.

#### Scenario: At-risk case identification
- **WHEN** a case has `startDate` of 25 days ago
- **AND** its case type has `processingDeadline` of `P30D` (30 days)
- **AND** the case status is not final
- **THEN** the system MUST include this case in the at-risk panel (5 days remaining = 16.7% < 25%)
- **AND** the system MUST display: case title, case identifier, case type name, days remaining, and a progress bar showing time consumed

#### Scenario: Already overdue cases in at-risk panel
- **WHEN** a case has exceeded its deadline (today > deadline)
- **AND** the case status is not final
- **THEN** the system MUST show the case in the at-risk panel with a red "Overdue" badge
- **AND** the system MUST show negative days remaining (e.g., "-3 days")

#### Scenario: Case without deadline
- **WHEN** a case's case type has no `processingDeadline`
- **AND** the case has no explicit `deadline` field
- **THEN** the system MUST NOT include the case in the at-risk panel

#### Scenario: At-risk panel sorting
- **WHEN** multiple cases are at risk
- **THEN** the system MUST sort them by urgency: overdue cases first (most overdue at top), then by least remaining time

### Requirement: Average processing time per case type table [V1]

The doorlooptijd dashboard SHALL display a summary table comparing actual average processing time against the SLA target for each case type.

@e2e exclude Average processing time table requires completed cases with specific case types; V1 data-dependent table scenarios not testable without pre-seeded data.

#### Scenario: Performance table with status indicators
- **WHEN** the user views the doorlooptijd dashboard
- **AND** case type "Vergunning" has target 30 days and average actual 24 days
- **AND** case type "Bezwaar" has target 42 days and average actual 48 days
- **THEN** the system MUST display a table with columns: Case Type, Target (days), Avg Actual (days), Compliance %, Status
- **AND** "Vergunning" MUST show a green status indicator (actual < target)
- **AND** "Bezwaar" MUST show a red status indicator (actual > target)

#### Scenario: Table sorting
- **WHEN** the user clicks a column header in the performance table
- **THEN** the system MUST sort the table by that column (toggle ascending/descending)

### Requirement: Date range filter [V1]

The doorlooptijd dashboard SHALL provide a date range filter that controls which completed cases are included in all analytics.

@e2e exclude Date range filter interactions require data to filter; V1 scenarios tested structurally as the filter controls render even with no data.

#### Scenario: Default date range
- **WHEN** the user first visits the doorlooptijd dashboard
- **THEN** the date range MUST default to the last 12 months
- **AND** all charts and tables MUST reflect data from that period

#### Scenario: Custom date range selection
- **WHEN** the user selects a custom date range (e.g., 2025-06-01 to 2025-12-31)
- **THEN** all charts, tables, and KPIs MUST update to reflect only cases completed within that range
- **AND** the at-risk panel MUST always show current open cases regardless of date range

#### Scenario: Quick date range presets
- **WHEN** the user opens the date range picker
- **THEN** the system MUST offer presets: "Last 3 months", "Last 6 months", "Last 12 months", "This year", "All time"

### Requirement: Navigation from main dashboard [V1]

The main dashboard SHALL provide navigation to the doorlooptijd analytics view.

@e2e exclude Navigation from the Procest in-app dashboard requires the dashboard to render its widget grid; the dashboard is marked fixme in pages.spec.ts due to a CI rendering issue.

#### Scenario: Link from main dashboard
- **WHEN** the user views the main Procest dashboard
- **THEN** the system MUST display a navigation element (tab or link) to access the doorlooptijd dashboard

### Requirement: Doorlooptijd page render [MVP]

The doorlooptijd page SHALL mount and render its page shell on navigation
(`DoorlooptijdDashboard.vue`, route `/doorlooptijd`), independently of whether
case data is present.

#### Scenario: Doorlooptijd page renders heading
- **GIVEN** an authenticated user on the Procest app
- **WHEN** they navigate to the doorlooptijd page
- **THEN** the main content MUST render a "Processing Time Analytics" page heading
- **AND** the page MUST NOT show an Internal Server Error

### Requirement: Empty state [V1]

The doorlooptijd dashboard SHALL handle the case when no data is available gracefully.

#### Scenario: No cases exist
- **WHEN** the user views the doorlooptijd dashboard
- **AND** there are zero cases in the system
- **THEN** the system MUST display an empty state message: "No case data available for doorlooptijd analysis"
- **AND** the system MUST NOT show broken charts or error states

#### Scenario: No case types with SLA targets

@e2e exclude This scenario requires case types to exist in the system but none having a processingDeadline configured; cannot be tested without pre-seeded case types, and with zero case types the "no cases exist" empty state takes precedence.

- **WHEN** all case types have no `processingDeadline` configured
- **THEN** the system MUST display a message: "No SLA targets configured. Set processing deadlines on case types in Settings to enable compliance tracking."
