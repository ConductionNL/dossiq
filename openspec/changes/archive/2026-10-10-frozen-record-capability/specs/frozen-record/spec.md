# Delta: frozen-record

## ADDED Requirements
### Requirement: A package declares which records freeze, and when (REQ-FRZ-001)

A freeze entry SHALL name the schema it guards (an id, a slug, or `config:<key>` for the app-config
key that holds the schema id), the statuses from which the record is frozen, and the status field
(default `currentStatus`). It MAY name the frozen fields (default: every content field except the
status, the listed mutable fields and the write-once fields), the write-once fields, the sealed
fields, the seal field (default `seal`), a successor, and the refusal rule and sentence. The
entries SHALL live in app config under `frozen_records` as a JSON list, written by a package's
settings. An entry without a schema, without a frozen status, or with a successor without a series
SHALL be refused when it is read; a refused entry SHALL be logged and skipped, and the other
entries SHALL still apply. With no entries configured, nothing SHALL be frozen.

#### Scenario: An incomplete entry does not silently freeze nothing

- **GIVEN** a configuration with one entry that names no frozen status and one valid entry
- **WHEN** the entries are read
- **THEN** the incomplete entry SHALL be logged as invalid and skipped
- **AND** the valid entry SHALL apply

### Requirement: A frozen record refuses change and delete through every writer (REQ-FRZ-002)

The system SHALL enforce the freeze on OpenRegister's pre-persist update and delete events, so the
rule holds whichever API writes the record. On a frozen record (decided by the STORED status) it
SHALL refuse with HTTP 409 and the entry's rule (default `record-frozen`): a change to a frozen
field, a change of status to one that is not frozen, a soft delete, and a permanent delete. A
payload that repeats the stored values SHALL change nothing, regardless of object key order. A
payload that cannot be read SHALL be refused on a frozen record. A write-once field SHALL accept
its first value at any status and refuse any other value after that.

#### Scenario: The frozen field does not move

- **GIVEN** a fixture record frozen at `issued` with `amount` frozen
- **WHEN** a write changes `amount`
- **THEN** the write SHALL be refused with HTTP 409 and the rule `record-frozen`
- **AND** a write that only changes an unfrozen field SHALL pass

#### Scenario: A frozen record does not thaw

- **GIVEN** a fixture record at `issued`
- **WHEN** a write sets its status to `draft`
- **THEN** the write SHALL be refused

### Requirement: The freezing write seals the record (REQ-FRZ-003)

When an entry names sealed fields, the write that moves a record into a frozen status SHALL store a
seal on it: the algorithm (`sha256`), the hash over a canonical form of the sealed fields (object
keys sorted at every depth, lists in order, an absent field as null), the field names, and the
moment. The seal SHALL be write-once. Verifying a record SHALL use the fields the seal names, so a
later change of the entry does not break earlier seals; a changed or emptied sealed field SHALL
fail verification, and a field added later that is not sealed SHALL NOT.

#### Scenario: Tampering is visible

- **GIVEN** a record sealed over `body`, `amount` and `reference`
- **WHEN** `amount` is changed in the database behind the API
- **THEN** verifying the record against its seal SHALL fail

### Requirement: A correction is a numbered successor (REQ-FRZ-004)

When an entry names a successor, a frozen record SHALL be corrected by a new record that takes the
next number of the entry's series (REQ-NDS-001) in its number field (default `reference`) and names
the record it replaces in its backward pointer (default `supersedes`); the replaced record SHALL
then carry the forward pointer (default `supersededBy`), which is write-once. A successor SHALL be
refused with 409 `record-not-frozen` for a record that is not frozen, with 409
`record-already-superseded` for a record already replaced, and with 422 `record-has-no-successor`
when the entry declares no successor.

#### Scenario: The chain stays linear

- **GIVEN** a frozen fixture record that already names a successor
- **WHEN** a second successor is requested
- **THEN** it SHALL be refused with HTTP 409 and the rule `record-already-superseded`
