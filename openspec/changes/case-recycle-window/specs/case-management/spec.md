## ADDED Requirements

### Requirement: A deleted case is recoverable for a stated period (REQ-CRW-01)

Deleting a case SHALL pass `case-delete-guard` first, and a permitted
delete SHALL put the case in openregister's recycle state rather than
destroying it. The case SHALL read as deleted, SHALL show the date its
recovery window ends, and SHALL be findable in a deleted lens on the case
list. dossiq SHALL NOT implement a soft delete of its own.

#### Scenario: a bezwaar deleted by mistake is still there
@e2e tests/e2e/case-recycle-window.spec.ts

- **GIVEN** a deleted bezwaar inside its recovery window
- **WHEN** a handler opens the deleted lens
- **THEN** the case SHALL be listed with the date its window ends

#### Scenario: the guard still refuses what it refused before
@e2e tests/e2e/case-recycle-window.spec.ts

- **GIVEN** a case the delete guard refuses
- **WHEN** a handler deletes it
- **THEN** the delete SHALL be refused
- **AND** the case SHALL NOT enter the recycle state

#### Scenario: dossiq ships no soft delete

- **GIVEN** the dossiq tree
- **WHEN** it is read for a soft-delete implementation
- **THEN** none SHALL exist

### Requirement: Restoring and destroying are separate, recorded acts (REQ-CRW-02)

Restoring a deleted case SHALL record who restored it and when.
Destroying one SHALL be a second act, distinct from deleting, SHALL record
who decided, and SHALL be permitted only to the role the case type
declares. Destroying SHALL also destroy the process data behind the case.

#### Scenario: a case is got back
@e2e tests/e2e/case-recycle-window.spec.ts

- **GIVEN** a deleted case inside its window
- **WHEN** a handler restores it
- **THEN** the case SHALL be live again
- **AND** the restore SHALL record who and when

#### Scenario: only the declared role destroys
@e2e tests/e2e/case-recycle-window.spec.ts

- **GIVEN** a case type declaring a destroying role
- **WHEN** a handler without that role tries to destroy a deleted case
- **THEN** the act SHALL be refused

#### Scenario: nothing survives a destruction
@e2e tests/e2e/case-recycle-window.spec.ts

- **GIVEN** a destroyed case
- **WHEN** its task history and notes are searched for
- **THEN** none SHALL remain

### Requirement: The lawful-purpose clock and the archive clock are kept apart (REQ-CRW-03)

A case SHALL carry a lawful-purpose end date and an archive retention date
as two separate, labelled values. Neither SHALL be derived from the other,
and dossiq SHALL NOT apply one rule to both.

#### Scenario: a case whose purpose ended is still archived
@e2e tests/e2e/case-recycle-window.spec.ts

- **GIVEN** a case whose lawful purpose has ended and whose retention runs to 2034
- **WHEN** the case is read
- **THEN** both dates SHALL be shown, labelled and different

#### Scenario: one rule does not set both dates

- **GIVEN** a case type's retention configuration
- **WHEN** the lawful-purpose date is set
- **THEN** the archive retention date SHALL be unchanged
