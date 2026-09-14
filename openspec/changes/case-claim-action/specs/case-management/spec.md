## ADDED Requirements

### Requirement: You claim an unassigned case with one click (REQ-CM-33)

`#CaseDetail` SHALL offer Claim when the case has no assignee. Claim SHALL
set `assignee` to the signed-in user through the object store and SHALL
record nothing beyond the platform audit row. `#Queue` and the Unclaimed lens
of `#Cases` SHALL offer Claim as a row action.

#### Scenario: Claim from the case page
@e2e tests/e2e/case-claim.spec.ts

- **GIVEN** an open case without an assignee
- **WHEN** you press Claim
- **THEN** the case header SHALL show you as assignee
- **AND** Claim SHALL no longer be offered

#### Scenario: Claim from the queue
@e2e tests/e2e/case-claim.spec.ts

- **GIVEN** the Queue lists an unassigned case
- **WHEN** you press Claim on its row
- **THEN** the row SHALL leave the Queue
- **AND** the case SHALL appear under Mine on Cases

### Requirement: You release a case you hold (REQ-CM-34)

`#CaseDetail` SHALL offer Release when you are the assignee. Release SHALL
clear `assignee`. A refused write SHALL show the platform's message and
change nothing.

#### Scenario: Release returns the case to the queue
@e2e tests/e2e/case-claim.spec.ts

- **GIVEN** a case assigned to you
- **WHEN** you press Release
- **THEN** the case SHALL have no assignee
- **AND** it SHALL be listed on the Queue
