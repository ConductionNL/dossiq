# case-management delta: case-page-reads-in-dutch

## ADDED Requirements

### Requirement: The case page shows labels and names, not codes (REQ-CM-82)

The case page SHALL show every enum value of the case it displays through a label in the reader's language, SHALL read a configured widget text through the app catalogue, and the Files tab SHALL show each file's name.

#### Scenario: A Dutch reader reads the derived priority
@e2e exclude {a register declaration; asserted in tests/Unit/Settings/CaseEnumLabelsTest.php}

- **GIVEN** a case whose `priorityDerived` is `normal`, on an nl_NL instance
- **WHEN** the handler opens the case
- **THEN** the field SHALL read "Normaal"

#### Scenario: The Files tab names its files
@e2e exclude {asserted against the library's own column normaliser in tests/vitest/filesTabColumns.spec.js}

- **GIVEN** a case with two documents in its folder
- **WHEN** the handler opens the Files tab
- **THEN** each row SHALL show the file's name
