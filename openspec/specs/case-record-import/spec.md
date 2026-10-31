# case-record-import Specification

## Purpose
What dossiq does for case record import. The requirements were first written by the change `record-import-moves-in-app-records` (archived 2026-10-30 under `openspec/changes/archive/2026-10-30-record-import-moves-in-app-records/`) and cover: A declaration reads nested, typed source values and answers the case type's fields (REQ-CRI-005); A case type without a statutory term imports without one (REQ-CRI-006); Declared imports run on upgrade (REQ-CRI-007); Records sharing a number become one case (REQ-CRI-008); and 1 more.

## Requirements

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

### Requirement: Records sharing a number become one case (REQ-CRI-008)

A declaration with `dedupeBy` SHALL treat a source record whose `dedupeBy` value is already kept as
a former reference (of the declared application) on a case of the type, or was imported earlier in
the same run, as that case: it is stamped with that case, its references are moved onto it, and it
is listed in `duplicates`, not in `migrated`.

#### Scenario: A seeded record twice is one case
- **GIVEN** two source records with the same case number
- **WHEN** the import runs twice
- **THEN** one case SHALL exist, the second record SHALL be in `duplicates` and stamped with that case, and the second run SHALL import nothing

### Requirement: References to a moved record move onto its case (REQ-CRI-009)

For each `references` entry `{schema, field, register?}`, every record whose field holds the moved
record's id or slug SHALL hold the case id afterwards, before the record is stamped; `repointed`
counts them. A record whose references could not all be moved SHALL NOT be stamped.

#### Scenario: Consent follows its case
- **GIVEN** consents pointing at a moved record by id and by slug, and one pointing elsewhere
- **WHEN** the record moves
- **THEN** the first two SHALL point at the case and the third SHALL be unchanged
