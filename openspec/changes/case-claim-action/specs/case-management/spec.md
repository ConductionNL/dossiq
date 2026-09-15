## ADDED Requirements

### Requirement: You claim an unassigned case with one click (REQ-CM-33)

`#CaseDetail` SHALL offer Claim on an open case. Claim SHALL set `assignee` to
the signed-in user and SHALL record nothing beyond the audit row the field
change already carries. A claim on a case that already has a handler SHALL be
refused with a status naming the rule, and SHALL leave the assignee unchanged.
`#Queue` and `#Cases` SHALL offer Claim as a row action.

#### Scenario: Claim from the case page
@e2e tests/e2e/case-claim.spec.ts

- **GIVEN** an open case without an assignee
- **WHEN** you press Claim
- **THEN** the case SHALL have you as assignee
- **AND** a second claim SHALL be refused, naming that you already hold it

#### Scenario: Claim from the queue
@e2e tests/e2e/case-claim.spec.ts

- **GIVEN** the Queue lists an unassigned case
- **WHEN** you press Claim on its row
- **THEN** the row SHALL leave the Queue
- **AND** the case SHALL appear under Mine on Cases

### Requirement: You release a case you hold (REQ-CM-34)

`#CaseDetail` SHALL offer Release on an open case. Release SHALL clear
`assignee`. A release by anyone who is not the assignee SHALL be refused with a
status naming the rule, SHALL show that refusal, and SHALL change nothing.

#### Scenario: Release returns the case to the queue
@e2e tests/e2e/case-claim.spec.ts

- **GIVEN** a case assigned to you
- **WHEN** you press Release
- **THEN** the case SHALL have no assignee
- **AND** it SHALL be listed on the Queue
