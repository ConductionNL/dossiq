# notices-table-reads-its-schema Delta

## ADDED Requirements

### Requirement: The notices table names its schema where the table reads it

The Termijnbewaking page's notices-of-default `table` widget SHALL carry `register`, `schema`, `filter` and `limit` on its
content.

#### Scenario: DqTermijnen

- **GIVEN** three stored notices of default
- **THEN** "Ingebrekestellingen en dwangsom" lists them
