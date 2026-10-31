# case-record-import (delta)

## ADDED Requirements

### Requirement: A declaration reads nested, typed source values and answers the case type's fields (REQ-CRI-005)

A `fields` entry's `from` SHALL be read as a dot path on the source record. `as` SHALL also accept
`boolean` (true for `true`, `1`, `"true"`, `"1"`, `"yes"`), `number` and `json` (a list or object
written as JSON text; an empty list or object is no value). An entry carrying
`property: {definition, name}` SHALL be appended to the list at its `to` path as
`{propertyDefinition: definition, name, value}`, with the value as text (`true`/`false` for a
boolean, JSON for a list or object). A record whose status is not declared SHALL take
`statuses["*"]` when declared, and is refused otherwise.

#### Scenario: A nested, typed record lands in the case type's fields
- **GIVEN** a declaration with a `property` entry over a nested boolean, a `json` entry over a list of objects and `statuses["*"]`
- **WHEN** a record without a status is mapped
- **THEN** `case.properties` SHALL hold one entry per answered field with its definition, name and text value
- **AND** the case SHALL carry the `*` status

#### Scenario: Existing declarations map as before
- **GIVEN** the Woo declaration
- **WHEN** a record is mapped
- **THEN** every value SHALL land as it did before this change

### Requirement: A case type without a statutory term imports without one (REQ-CRI-006)

A declaration with `carriesTerm: false` SHALL NOT carry a term: the case is written and the source
stamped, and the record counts as moved whatever the term engine would answer.

#### Scenario: A record moves onto a case type with no term
- **GIVEN** a declaration with `carriesTerm: false`
- **WHEN** a running record is imported
- **THEN** it SHALL be in `migrated` and the term engine SHALL NOT be asked

### Requirement: Declared imports run on upgrade (REQ-CRI-007)

`CaseRecordImport::runOnUpgrade()` SHALL run, as the system, every declaration flagged
`runOnUpgrade: true` of every case type version in use (not superseded, not a draft), and answer
one entry per declaration as `run()` does. The repair step `RunUpgradeRecordImports` SHALL call it
after `InstallShippedPackages` and print the counts per declaration, warning on each failure
without failing the upgrade.

#### Scenario: An upgrade moves the flagged records once
- **GIVEN** a case type in use whose declaration is flagged `runOnUpgrade`, and one that is not
- **WHEN** the upgrade runs twice
- **THEN** only the flagged declaration SHALL run, and the second run SHALL import nothing
