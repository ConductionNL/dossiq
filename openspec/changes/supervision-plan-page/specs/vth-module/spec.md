# vth-module Delta: supervision-plan-page

## ADDED Requirements

### Requirement: The supervision plan has a page of its own

The system SHALL offer a Supervision plan page that shows a year's plan as board DqToezichtplan draws it: the planned, completed and cancelled inspections in total and per category, the completion share, the capacity against the demand, a quarterly overview and the planned inspections on a map. Completed and cancelled SHALL be counted from the inspection records, not stored on the plan.

#### Scenario: The plan shows its progress per category

- **GIVEN** the 2026 plan with 40 hospitality, 60 construction and 50 environment inspections planned
- **AND** 15 hospitality, 22 construction and 18 environment inspections completed
- **WHEN** the supervisor opens the Supervision plan page for 2026
- **THEN** the page SHALL show Scheduled 150 and Completed 55
- **AND** a bar per category reading "15 of 40", "22 of 60" and "18 of 50"

#### Scenario: A completed inspection counts at once

- **GIVEN** the 2026 plan and a construction inspection that is still planned
- **WHEN** the inspector completes that inspection
- **THEN** the Supervision plan page SHALL count it under completed for construction without anyone editing the plan

### Requirement: A schedule is proposed before it is written

"Suggest a schedule" SHALL propose inspection dates from the frequency per risk profile and the available capacity, and SHALL show the proposal for approval before any inspection is planned.

#### Scenario: The proposal waits for approval

- **GIVEN** the 2026 plan with capacity 1,600 hours
- **WHEN** the supervisor chooses Suggest a schedule
- **THEN** the page SHALL show the proposed dates per category
- **AND** no inspection SHALL be planned until the supervisor accepts the proposal
