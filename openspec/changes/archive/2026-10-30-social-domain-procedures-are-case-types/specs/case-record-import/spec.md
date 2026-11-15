# case-record-import (delta)

## ADDED Requirements

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
