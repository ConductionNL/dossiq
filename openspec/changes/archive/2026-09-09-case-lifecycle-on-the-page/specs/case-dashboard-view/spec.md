## ADDED Requirements

### Requirement: The case shows its step (REQ-CDV-13)

You see which step the case is in and how many steps remain. `CaseDetail`
SHALL render a stepper over the case type's status types in their `order`,
with the current status marked as active and the statuses before it as done.
The stepper SHALL take the place of the milestone progress tile.

#### Scenario: The current step is marked
@e2e tests/e2e/case-lifecycle-on-the-page.spec.ts

- **GIVEN** a case type with statuses Ontvangen, In behandeling, Afgehandeld in that order
- **AND** a case in status In behandeling
- **WHEN** the handler opens the case page
- **THEN** the stepper SHALL show three stages
- **AND** Ontvangen SHALL be done, In behandeling active, Afgehandeld pending

#### Scenario: The stepper follows a transition
@e2e tests/e2e/case-lifecycle-on-the-page.spec.ts

- **GIVEN** the same case
- **WHEN** the handler moves it to Afgehandeld
- **THEN** the stepper SHALL mark Afgehandeld as active without a reload

#### Scenario: The progress tile is gone
@e2e tests/e2e/case-detail-kpis-and-tabs.spec.ts

- **WHEN** the handler opens any case page
- **THEN** no tile labelled Completed SHALL render in the KPI row
- **AND** the page SHALL make no request to `/milestones/progress`
