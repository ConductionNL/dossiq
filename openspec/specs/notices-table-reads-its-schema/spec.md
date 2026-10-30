# notices-table-reads-its-schema Specification

## Purpose
What dossiq does for notices table reads its schema. The requirements were first written by the change `notices-table-reads-its-schema` (archived 2026-10-30 under `openspec/changes/archive/2026-10-30-notices-table-reads-its-schema/`) and cover: The notices table names its schema where the table reads it.

## Requirements

### Requirement: The notices table names its schema where the table reads it

The Termijnbewaking page's notices-of-default `table` widget SHALL carry `register`, `schema`, `filter` and `limit` on its
content.

#### Scenario: DqTermijnen

- **GIVEN** three stored notices of default
- **THEN** "Ingebrekestellingen en dwangsom" lists them
