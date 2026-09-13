## ADDED Requirements

### Requirement: A case can wait on another case (REQ-RCL-10)

`CaseRelationService` SHALL accept the relation type `waitsOn` and write
its inverse `blocks` on the other case. The Related tab SHALL show both
directions.

#### Scenario: Link a vergunning to the bezwaar it waits on
@e2e tests/e2e/dependent-term.spec.ts

- **GIVEN** a vergunning case and a bezwaar case
- **WHEN** you relate the vergunning to the bezwaar as waits on
- **THEN** the vergunning's Related tab SHALL show the bezwaar under Waits on
- **AND** the bezwaar's Related tab SHALL show the vergunning under Blocks

### Requirement: A moved term is offered to its dependents (REQ-RCL-11)

When a `deadlineEvent` of kind `verleng` or `pauze` is recorded on a case,
each case that waits on it SHALL receive an engine task for its handler
naming the source case and the days moved, with one action that extends the
dependent's active term by those days with the reason "follows <source>"
through `DeadlineExtensionService`. No term SHALL move without that action.

#### Scenario: The handler is offered the extension
@e2e tests/e2e/dependent-term.spec.ts

- **GIVEN** the vergunning waits on the bezwaar
- **WHEN** the bezwaar's term is extended by 14 days
- **THEN** the vergunning's handler SHALL have a task naming the bezwaar and 14 days
- **AND** the vergunning's term SHALL be unchanged

#### Scenario: Accepting extends with the reason
@e2e tests/e2e/dependent-term.spec.ts

- **GIVEN** that task
- **WHEN** the handler accepts it
- **THEN** the vergunning's `endDateCurrent` SHALL move by 14 days
- **AND** the `deadlineEvent` SHALL carry the reason "follows" and the bezwaar's id

#### Scenario: The ceiling still applies
@e2e exclude covered by DependentTermListenerTest over the extension service stub, refused branch

- **GIVEN** a dependent term at its extension ceiling
- **WHEN** the handler accepts
- **THEN** the extension SHALL be refused with the same status a manual one gets
