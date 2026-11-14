## ADDED Requirements

### Requirement: REQ-TASK-021 The Tasks index has five search fields

The `#Tasks` sidebar SHALL offer filters on case, assignee, due date range,
state and priority. Each SHALL be answered by the engine inbox server-side;
no filter SHALL be applied over a fetched page. A lens and a field SHALL
compose, and both SHALL be carried in the URL.

#### Scenario: Narrow to one case
@e2e tests/e2e/task-search-fields.spec.ts

- **GIVEN** tasks on two cases
- **WHEN** you pick one case in the sidebar
- **THEN** only that case's tasks SHALL remain
- **AND** the URL SHALL carry the case filter

#### Scenario: Due window inside a lens
@e2e tests/e2e/task-search-fields.spec.ts

- **GIVEN** the Mine lens is active
- **WHEN** you set due between next Monday and Friday
- **THEN** only your tasks due in that week SHALL remain

#### Scenario: A filter the engine lacks is not faked
@e2e exclude structural; covered by a vitest asserting every declared sidebar field has a store mapping to an inbox argument

- **GIVEN** the sidebar declaration
- **WHEN** the store mapping is read
- **THEN** every declared field SHALL map to an inbox argument
