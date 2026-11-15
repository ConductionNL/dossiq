# termijn-reporting Delta: lists-and-dashboards-as-drawn

## ADDED Requirements

### Requirement: The term dashboard lists the terms that end first

The term dashboard SHALL draw "Verloopt het eerst" between the notices of default and the quarterly report: the running terms that end first, each with its case, its end date, the share of the term that has passed and the days left. Progress and days left SHALL be computed at read time, as REQ-TERM-068 requires. The card SHALL NOT be drawn while its source does not answer.

#### Scenario: A controller reads the terms that end first
@e2e exclude needs the SIG endpoint GET /api/deadlines/ending-first; the configuration is covered by tests/vitest/termijnEndingFirst.spec.js
- **GIVEN** running terms on several cases
- **WHEN** a controller opens the term dashboard
- **THEN** the card lists the soonest ending terms with progress and days left
- **AND** a row opens its case
