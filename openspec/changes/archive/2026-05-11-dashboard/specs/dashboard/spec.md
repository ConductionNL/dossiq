# Delta: dashboard

## ADDED Requirements
### Requirement: REQ-DASH-003 — Implemented
The system SHALL satisfy the behaviour described as "REQ-DASH-003 — Implemented".

- Added Cases by Type horizontal bar chart widget to Dashboard.vue
- Aggregates open cases by case type name, sorted by count descending
- Click on bar navigates to Cases view filtered by type
- Uses same CSS bar chart pattern as Cases by Status

#### Scenario: behaviour satisfied

- **GIVEN** the system is configured per this requirement
- **WHEN** the described trigger occurs
- **THEN** the system SHALL exhibit the documented behaviour

### Requirement: Application widget registration — Fix
The system SHALL satisfy the behaviour described as "Application widget registration — Fix".

- Registered CasesOverviewWidget, MyTasksWidget, OverdueCasesWidget in Application.php
- Fixed CasesOverviewWidget route from `.dashboard.index` to `.dashboard.page`

#### Scenario: behaviour satisfied

- **GIVEN** the system is configured per this requirement
- **WHEN** the described trigger occurs
- **THEN** the system SHALL exhibit the documented behaviour
