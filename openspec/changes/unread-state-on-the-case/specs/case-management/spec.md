## ADDED Requirements

### Requirement: A case carries a per-user read state (REQ-URS-01)

dossiq SHALL render openregister's per-user read state on `#Cases` and
`#Queue`, and SHALL offer mark as read and mark as unread on a case and on
a single message. dossiq SHALL NOT store a read state of its own.

#### Scenario: what moved overnight is visible from the list
@e2e tests/e2e/unread-state-on-the-case.spec.ts

- **GIVEN** a queue of cases, three of which changed since the handler last looked
- **WHEN** they open the queue
- **THEN** those three SHALL read unread

#### Scenario: a handler puts a case back to unread
@e2e tests/e2e/unread-state-on-the-case.spec.ts

- **GIVEN** a case the handler has opened
- **WHEN** they mark it unread
- **THEN** it SHALL read unread in the list again

#### Scenario: dossiq stores no read state

- **GIVEN** the dossiq tree
- **WHEN** it is read for a per-user read marker
- **THEN** none SHALL exist

### Requirement: The case's tabs say where the unread thing is (REQ-URS-02)

A case SHALL show which of its tabs hold something unread, so a handler
sees where to look rather than only that something changed.

#### Scenario: a document waiting on a case is visible from its header
@e2e tests/e2e/unread-state-on-the-case.spec.ts

- **GIVEN** a case with a newly added document
- **WHEN** a handler opens the case
- **THEN** the Documents tab SHALL read unread

#### Scenario: reading the tab clears its badge
@e2e tests/e2e/unread-state-on-the-case.spec.ts

- **GIVEN** a case whose Documents tab reads unread
- **WHEN** the handler opens that tab
- **THEN** the tab SHALL no longer read unread

### Requirement: A notification clears when its subject is opened (REQ-URS-03)

When a handler opens the thing a notification was about, that notification
SHALL clear without a separate dismissal.

#### Scenario: doing the work empties the bell
@e2e tests/e2e/unread-state-on-the-case.spec.ts

- **GIVEN** a notification about a case
- **WHEN** the handler opens that case
- **THEN** the notification SHALL clear

### Requirement: What makes a case unread is declared per case type (REQ-URS-04)

A case type SHALL declare which changes make a case unread, defaulting to
a status change, a new document and a new message. A change the case type
does not name SHALL NOT make a case unread.

#### Scenario: a bulk correction does not light up four hundred rows
@e2e tests/e2e/unread-state-on-the-case.spec.ts

- **GIVEN** a case type that does not name a field among its unread changes
- **WHEN** that field is changed in bulk over four hundred cases
- **THEN** none of them SHALL read unread

#### Scenario: a status change does light up a row
@e2e tests/e2e/unread-state-on-the-case.spec.ts

- **GIVEN** a case type with the default declaration
- **WHEN** a case's status changes
- **THEN** it SHALL read unread for the handlers who have seen it before
