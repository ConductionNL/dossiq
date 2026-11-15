## ADDED Requirements

### Requirement: The write actions show on a case that is not archived (REQ-CM-73)
The seven header actions that write to a case (Add party, Link object, Log
contact, Generate document, Record receipt confirmed, Plan follow-up and Remind)
MUST show on a case that carries no archive marker, whether the marker is absent
or null. They MUST stay hidden on an archived case.

#### Scenario: A working case offers its write actions
@e2e exclude The gate is evaluated with the library's own evaluator in archivedGateEvaluates.spec.js, against the `@self` block a live case answered; the coordinator checks the live case page after merge.
- **GIVEN** a case whose `@self` block has no `archived` key
- **WHEN** a case handler opens the case
- **THEN** all seven write actions MUST be offered

#### Scenario: An archived case does not
@e2e exclude Same evaluator test; archived-cases-leave-the-lenses.spec.ts covers the archived page in a browser.
- **GIVEN** a case whose `@self.archived` holds the archive marker
- **WHEN** a case handler opens the case
- **THEN** none of the seven write actions may be offered
- **AND** the Lifecycle menu MUST still be offered, because Restore lives in it
