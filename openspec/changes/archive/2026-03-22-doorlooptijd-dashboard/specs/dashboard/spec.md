# Dashboard — Delta Spec (doorlooptijd-dashboard)

## ADDED Requirements

### Requirement: SLA Compliance KPI Card [V1]

The main dashboard KPI row SHALL include an SLA Compliance card that shows the overall SLA compliance percentage and links to the doorlooptijd dashboard for detailed analytics.

#### Scenario: SLA compliance KPI with link
- **WHEN** the user views the main dashboard
- **AND** 82 out of 100 completed cases (this month) were within their SLA target
- **THEN** the system MUST display a KPI card titled "SLA Compliance"
- **AND** the card MUST show "82%"
- **AND** the card MUST show a sub-label with the count (e.g., "82/100 within SLA")
- **AND** clicking the card MUST navigate to the doorlooptijd dashboard view

#### Scenario: No SLA data available
- **WHEN** the user views the main dashboard
- **AND** no completed cases this month have case types with `processingDeadline` set
- **THEN** the SLA Compliance KPI card MUST show "—" instead of a percentage
- **AND** the sub-label MUST show "No SLA targets"

### Requirement: Doorlooptijd navigation link [V1]

The main dashboard SHALL provide a visible navigation element to access the doorlooptijd analytics view.

#### Scenario: Navigation tab to doorlooptijd
- **WHEN** the user views the main dashboard
- **THEN** the system MUST display a tab or button labeled "Doorlooptijd" in the dashboard header area
- **AND** clicking it MUST navigate to the `/doorlooptijd` route

## MODIFIED Requirements

### REQ-DASH-001: KPI Cards Row [MVP]

The dashboard MUST display a row of five KPI cards at the top (expanded from four), providing headline metrics for the current user's case management workload. The fifth card is the SLA Compliance card defined above.

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
