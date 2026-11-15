# quality-gates Delta: hydra-gates-green-on-development

**Status**: draft
**Scope**: the blocking Hydra gate findings on `development`, and which of them this repo can answer.

## ADDED Requirements

### Requirement: Every data table associates its headers with its cells

Every `<table>` this app renders SHALL declare `scope=` on each of its `<th>` elements, so a screen reader can name the column or row a data cell belongs to (WCAG 2.2 AA SC 1.3.1).

#### Scenario: The review batch table is read with a screen reader

- **GIVEN** a case with two review batches
- **WHEN** a screen reader user moves across a batch row
- **THEN** each cell is announced with its column name
- **AND** `check_table_headers.py` reports no unscoped header in this repo

### Requirement: Every case type property a person edits has a label

Every property of every schema in this app's register SHALL carry an English `title` and `description`, and both SHALL have an `en` and an `nl` catalogue entry.

#### Scenario: A case type manager opens the sessions editor

- **GIVEN** a case type with a session
- **WHEN** the manager opens the session fields
- **THEN** each field shows a label in words, not a key such as `attendanceGraceHours`
- **AND** a Dutch user sees the Dutch label

### Requirement: A capability is named by what it does, not by a procedure

A class SHALL be named, and SHALL live in a namespace named, after what it does. A procedure stays configuration: a case type, a flow, a schema, seed data (decision 182).

#### Scenario: A case's number is read for a letter

- **GIVEN** a letter that has to name the case it is about
- **WHEN** it asks for the number behind a case UUID
- **THEN** it asks `OCA\Dossiq\Service\CaseNumberReader`, which names the job and no procedure
- **AND** the gate-119 count of procedure-named files does not grow
