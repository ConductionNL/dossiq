# termijnbewaking-schemas

## ADDED Requirements

### Requirement: An administrator maintains the working calendar in one place (REQ-CRS-20)

An administrator SHALL maintain the working days and closure days on one settings page. A closure day added there SHALL count as a non-working day in the next term computed by any part of dossiq.

Rows: `8.12` (dossiq matrix).

#### Scenario: A local closure day
- **GIVEN** an administrator who adds 2 January as a closure day
- **WHEN** a five working day term starts on 31 December
- **THEN** the term skips 2 January
