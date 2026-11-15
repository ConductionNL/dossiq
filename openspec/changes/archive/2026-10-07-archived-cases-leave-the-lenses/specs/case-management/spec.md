## ADDED Requirements

### Requirement: A closed case is archived and comes back (REQ-CM-41)

`#CaseDetail` SHALL offer Archive on a case in a final status, writing the
platform's archive state, and SHALL offer Restore on an archived case.
Archiving SHALL also set `case.archiveStatus` to `gearchiveerd` and
restoring SHALL set it back, with the platform's marker as the state that
is read. The ZGW delete guard SHALL be unchanged.

#### Scenario: archive a closed case
@e2e tests/e2e/archived-cases-leave-the-lenses.spec.ts

- **GIVEN** a case in a final status
- **WHEN** a handler presses Archive
- **THEN** the case SHALL be archived
- **AND** `case.archiveStatus` SHALL read `gearchiveerd`

#### Scenario: archive is not offered on a running case
@e2e tests/e2e/archived-cases-leave-the-lenses.spec.ts

- **GIVEN** a case in a status that is not final
- **WHEN** a handler opens the actions menu
- **THEN** Archive SHALL NOT be offered

#### Scenario: restore in one action
@e2e tests/e2e/archived-cases-leave-the-lenses.spec.ts

- **GIVEN** an archived case
- **WHEN** a handler presses Restore
- **THEN** the case SHALL be back in the working lenses
- **AND** `case.archiveStatus` SHALL read its previous value

### Requirement: Archived cases leave the working lenses and keep one of their own (REQ-CM-42)

`#Cases` and `#Queue` SHALL exclude archived cases from every lens except
an Archived lens, and `#MyWorkHome` tiles and the case search SHALL take
the same default. The Archived lens SHALL add no navigation entry.

#### Scenario: the archived case is gone from Cases
@e2e tests/e2e/archived-cases-leave-the-lenses.spec.ts

- **GIVEN** ten cases of which three are archived
- **WHEN** a handler opens Cases
- **THEN** seven SHALL be listed

#### Scenario: the Archived lens finds them
@e2e tests/e2e/archived-cases-leave-the-lenses.spec.ts

- **GIVEN** the same ten cases
- **WHEN** the handler opens the Archived lens
- **THEN** the three archived cases SHALL be listed

#### Scenario: search does not return an archived case
@e2e tests/e2e/archived-cases-leave-the-lenses.spec.ts

- **GIVEN** an archived case whose title contains a distinctive word
- **WHEN** a handler searches for that word
- **THEN** the archived case SHALL NOT be in the results

#### Scenario: a tile and its list agree
@e2e tests/e2e/archived-cases-leave-the-lenses.spec.ts

- **GIVEN** the same ten cases
- **WHEN** the handler reads the My work tile counting them
- **THEN** the tile SHALL count seven

### Requirement: An archived case is read-only and says so (REQ-CM-43)

An archived case SHALL render without edit affordances. A write attempted
on it anyway SHALL show the platform's refusal message rather than
failing silently.

#### Scenario: the fields cannot be edited
@e2e tests/e2e/archived-cases-leave-the-lenses.spec.ts

- **GIVEN** an archived case
- **WHEN** a handler opens it
- **THEN** no field SHALL be editable and no status transition SHALL be offered

#### Scenario: a write shows the reason
@e2e tests/e2e/archived-cases-leave-the-lenses.spec.ts

- **GIVEN** an archived case and a handler who reaches a write another way
- **WHEN** the write is attempted
- **THEN** the platform's refusal message SHALL be shown, naming the archive
