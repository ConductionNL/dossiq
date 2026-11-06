## ADDED Requirements

### Requirement: A handler runs a grant from its case page (REQ-SUB-CASE-01)

The case page of a grant case SHALL show the decision with its advance schedule, the interim reports with their assessment, the evidence and the settlement, and SHALL offer the actions to record and assess an interim report, settle the grant and start a recovery. Each action SHALL use the existing grant routes and record its outcome on the case history.

#### Scenario: Recording and assessing an interim report

- **GIVEN** a granted subsidy with an interim report due
- **WHEN** the handler records the report and assesses it as sufficient
- **THEN** the report SHALL be linked to the case with assessment "voldoende" and the history SHALL show both steps
- @e2e tests/e2e/grant-case.spec.ts

#### Scenario: Settling lower than granted starts a recovery

- **GIVEN** a grant of 50.000 euro with 40.000 euro paid in advances
- **WHEN** the handler settles it at 35.000 euro
- **THEN** a recovery of 5.000 euro SHALL be proposed on the case
- @e2e tests/e2e/grant-case.spec.ts

### Requirement: Advances are a payment schedule raised with finance when due (REQ-SUB-CASE-02)

A decision SHALL be able to carry a payment schedule (date and amount per instalment), and each instalment SHALL be raised with the finance app as a payable on its date, once.

#### Scenario: An instalment is raised once on its date

- **GIVEN** a decision with instalments on 1 January and 1 July
- **WHEN** the schedule job runs on 1 January twice
- **THEN** one payable for the January instalment SHALL be raised
- @e2e exclude a scheduled job has no browser surface; asserted in PHPUnit
