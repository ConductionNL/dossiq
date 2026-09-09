## ADDED Requirements

### Requirement: REQ-TASK-017 The Tasks index MUST carry the same six lenses as Cases

You narrow the task list to closed, late or this week's work without leaving
it. The `Tasks` page (`src/manifest.json`, type `index` over `caseTask`) SHALL
extend its `quickFilters` with Closed (`isTerminalStatus = true`), Overdue
(`dueDate[lt] = "@today"`, `isTerminalStatus = false`) and Due this week
(`dueDate[gte] = "@today"`, `dueDate[lt] = "@today+7d"`,
`isTerminalStatus = false`), so both index pages declare the same six labels
in the same order and only the underlying field differs.

The far edge SHALL be `lt` rather than `lte`, so a task due on day seven
belongs to next week's window and not to two windows at once.

Each operator SHALL be spelled as a flat bracket key. The nested
`{ dueDate: { lt } }` form is JSON-stringified by `buildQueryString` and
reaches the API as a literal string that matches nothing, with no error.

#### Scenario: Closed shows the completed task
@e2e tests/e2e/case-list-lenses.spec.ts

- **GIVEN** a completed task and an open task assigned to the signed-in user
- **WHEN** you choose the chip Closed
- **THEN** the list SHALL show the completed task and SHALL NOT show the open one

#### Scenario: A task due later today is not overdue
@e2e tests/e2e/case-list-lenses.spec.ts

- **GIVEN** an open task due two days ago, an open task due at nine this morning and an open task due in thirty days
- **WHEN** you choose the chip Overdue
- **THEN** the list SHALL show the task due two days ago
- **AND** the list SHALL NOT show the task due this morning, because a task due later today is not late yet
- **AND** the list SHALL NOT show the task due in thirty days

#### Scenario: Due this week holds both edges of the window
@e2e tests/e2e/case-list-lenses.spec.ts

- **GIVEN** an open task due two days ago, an open task due at nine this morning, an open task due in two days and an open task due in thirty days
- **WHEN** you choose the chip Due this week
- **THEN** the list SHALL show the task due this morning and the task due in two days
- **AND** the list SHALL NOT show the task due two days ago or the task due in thirty days

### Requirement: REQ-TASK-018 The task row MUST show its priority

You see which task jumps the queue without opening it. The `Tasks` page SHALL
declare `priority` as a column, after `dueDate`, because the two answer the
same question in order: when is this due, and does it come first anyway.

`caseTask.priority` is `facetable`, so before this it was reachable through the
sidebar facet and absent from the row. REQ-TASK-004's first scenario has always
required the row to show it.

#### Scenario: The task list shows a priority column
@e2e tests/e2e/case-list-lenses.spec.ts

- **WHEN** you open the Tasks page
- **THEN** the table SHALL carry a Priority column header
