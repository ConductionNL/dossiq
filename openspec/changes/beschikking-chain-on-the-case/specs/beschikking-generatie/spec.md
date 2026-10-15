## ADDED Requirements

### Requirement: The case shows which beschikking was served (REQ-BES-013)

The case page SHALL list every beschikking of the case in issue order, each with its reference
number, and SHALL say for each whether it is in force or which beschikking replaced it. A
beschikking is in force while it names no successor.

**Feature tier**: V1

#### Scenario: The case shows which beschikking was served

- **GIVEN** a case with a beschikking and two corrections
- **WHEN** the case is opened
- **THEN** all three SHALL be listed in issue order with their reference numbers
- **AND** each SHALL show whether it is in force or has been replaced

#### Scenario: A correction can be started from a signed beschikking

- **GIVEN** a signed beschikking that names no successor
- **WHEN** a handler with mutation access on the case chooses to correct it
- **THEN** a wijzigingsbeschikking SHALL be created as a draft with its own number
- **AND** the list SHALL show the original as replaced by it
