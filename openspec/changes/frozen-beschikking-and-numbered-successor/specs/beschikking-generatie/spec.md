## MODIFIED Requirements

### Requirement: Niet-wijzigbare beschikking na ondertekening (REQ-BES-008)

A beschikking with status `signed` or later SHALL NOT be edited substantively; only process
events (delivery, receipt confirmation, bezwaar linking) are allowed.

The freeze SHALL be enforced at the persistence boundary, not in a single service method. Any
write that reaches the object store SHALL be refused, whichever route it arrived on: the
dossiq API, OpenRegister's generic object API, or an import. The stored state decides whether
a write is refused, never the incoming payload.

A refused write SHALL name the successor as the way forward, so a handler who is told no is
also told what to do instead.

**Feature tier**: V1

#### Scenario: Substantive edit is rejected after ondertekend

- **GIVEN** a beschikking with status `signed`
- **WHEN** an attempt is made to modify `rationale` or `decision`
- **THEN** the system SHALL reject the PATCH with HTTP 409
- **AND** the response SHALL include a message that a wijzigingsbeschikking or an
  intrekkingsbeschikking must be created instead

#### Scenario: The generic object API is refused the same edit

- **GIVEN** a beschikking with status `sent`
- **WHEN** a client writes `rationale` through OpenRegister's object API, bypassing the dossiq
  beschikking routes
- **THEN** the write SHALL be refused before the row is changed
- **AND** the stored beschikking SHALL be byte-identical to what it was before the attempt

#### Scenario: A signed beschikking cannot be deleted

- **GIVEN** a beschikking with status `archived`
- **WHEN** a delete is attempted through any route
- **THEN** the delete SHALL be refused before the row is removed

#### Scenario: Process events stay allowed after signing

- **GIVEN** a beschikking with status `signed`
- **WHEN** the dispatch record, the receipt confirmation or the bezwaar link is written
- **THEN** the write SHALL succeed
- **AND** the decision content SHALL be unchanged

#### Scenario: A draft is still editable

- **GIVEN** a beschikking with status `draft` or `approved-mandate`
- **WHEN** `rationale` is modified
- **THEN** the write SHALL succeed

#### Scenario: Wijzigingsbeschikking references the original

- **GIVEN** a handler decides to correct a signed beschikking
- **WHEN** they issue a wijzigingsbeschikking
- **THEN** a new beschikking SHALL be created that references the original
- **AND** the original SHALL remain signed and unmodified
- **AND** the successor SHALL follow REQ-BES-012 for its number and its chain pointers

### Requirement: Data Model for Beschikking (REQ-BES-011)

The Dossiq register SHALL define the `beschikking`, `stateMachineLog`, `bezwaarTrigger`, and
`mandateArrangement` entities with all required properties, constraints, and relations per the
data model in design.md.

**Feature tier**: V1

#### Scenario: Beschikking entity is fully queryable

- **GIVEN** a Dossiq instance with seeded beschikkingen
- **WHEN** the system queries `GET /api/beschikkingen?currentStatus=signed`
- **THEN** the response SHALL include all signed beschikkingen with their full payloads

#### Scenario: Immutability of ondertekend beschikking is enforced at schema level

- **GIVEN** the `beschikking` schema, whose content fields must stay editable while the
  beschikking is a draft
- **WHEN** immutability is enforced
- **THEN** it SHALL be enforced by a guard that reads the stored `currentStatus` at write time
- **AND** it SHALL NOT be expressed as a `readOnly` property flag, because that freezes a
  property from creation onward and would make a draft unwritable

## ADDED Requirements

### Requirement: A schema a service resolves SHALL have a configured key

Every OpenRegister schema that dossiq code resolves through an appconfig key SHALL have that
slug registered in the schema slug map and that key in the settings allowlist. A key nothing
writes leaves the service that reads it dead, and a dead service reports no error until a user
calls it.

**Feature tier**: V1

#### Scenario: Every resolved schema key is reconciled

- **WHEN** the schema key reconciler runs after the register is imported
- **THEN** every appconfig key that app code passes to the config resolver SHALL have been
  written with a live schema id

#### Scenario: The beschikking lifecycle resolves its four schemas

- **GIVEN** a Dossiq instance with the register imported
- **WHEN** a beschikking, a state machine log entry, a bezwaar trigger or a mandate
  arrangement is saved
- **THEN** the save SHALL resolve its schema and succeed
- **AND** it SHALL NOT fail with a not-configured error

### Requirement: A correction is a numbered successor, never an edit (REQ-BES-012)

A handler who must correct a signed beschikking SHALL do so by issuing a new beschikking that
references the one it replaces. The original SHALL remain exactly as it was served.

The successor SHALL carry its own reference number, distinct from the original's, and SHALL
record which beschikking it replaces. The original SHALL record which beschikking replaced it,
so the chain reads in both directions without a query.

A beschikking that has already been replaced SHALL NOT be replaced a second time. The chain is
linear: the correction of a correction succeeds the correction, not the original.

**Feature tier**: V1

#### Scenario: A correction is issued as a successor

- **GIVEN** a beschikking with status `sent` and reference `Z/2026/04832/B01`
- **WHEN** a handler issues a correction
- **THEN** a new beschikking SHALL be created with `decisionType: amendment`
- **AND** the new beschikking SHALL record the original's id
- **AND** the new beschikking SHALL carry its own reference, distinct from `Z/2026/04832/B01`
- **AND** the original SHALL still read `sent`, with its content unchanged

#### Scenario: The original points forward to its successor

- **GIVEN** a beschikking that has been replaced by a correction
- **WHEN** the original is read
- **THEN** it SHALL name the beschikking that replaced it
- **AND** that pointer SHALL be the only field the freeze allows a successor to write on it

#### Scenario: A withdrawal is a successor too

- **GIVEN** a signed beschikking a handler must withdraw
- **WHEN** an intrekkingsbeschikking is issued
- **THEN** it SHALL be created with `decisionType: withdrawal` and reference the original
- **AND** the original SHALL remain readable in the form it was served

#### Scenario: A superseded beschikking cannot be superseded twice

- **GIVEN** a beschikking that already names a successor
- **WHEN** a second correction of that same beschikking is attempted
- **THEN** the attempt SHALL be refused
- **AND** the refusal SHALL name the successor to correct instead

#### Scenario: The case shows which beschikking was served

- **GIVEN** a case with a beschikking and two corrections
- **WHEN** the case is opened
- **THEN** all three SHALL be listed in issue order with their reference numbers
- **AND** each SHALL show whether it is in force or has been replaced
