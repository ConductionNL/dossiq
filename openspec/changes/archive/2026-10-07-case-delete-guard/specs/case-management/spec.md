## ADDED Requirements

### Requirement: A held case cannot be deleted, and you are told why (REQ-CM-35)

A delete of a `case` SHALL be refused while any of these hold: an open
statutory term (`deadlineInstance` in `lopend`, `verlengd` or `paused`), a
case naming it as `parentCase`, an active legal hold, or a closed case still
inside its retention period. The refusal SHALL name every rule that holds in
one message and SHALL carry a 4xx status.

#### Scenario: An open term blocks the delete
@e2e tests/e2e/case-delete-guard.spec.ts

- **GIVEN** an open case with a running beslistermijn
- **WHEN** you delete the case
- **THEN** the delete SHALL be refused with status 409
- **AND** the message SHALL name the open term

#### Scenario: Two rules are named together
@e2e exclude covered by CaseDeleteGuardListenerTest over the store stub; the two-rule fixture needs a sub-case and a term on one case

- **GIVEN** a case with a sub-case and a running term
- **WHEN** the delete event fires
- **THEN** the event SHALL be stopped
- **AND** the message SHALL name both the sub-case and the term

#### Scenario: A free case is deleted
@e2e tests/e2e/case-delete-guard.spec.ts

- **GIVEN** a closed case past its retention with no sub-cases and no hold
- **WHEN** you delete it
- **THEN** the delete SHALL succeed
