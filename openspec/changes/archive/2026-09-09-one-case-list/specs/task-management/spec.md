## ADDED Requirements

### Requirement: REQ-TASK-016 Task lenses MUST sit on the Tasks index

You switch between your tasks, unclaimed tasks and all tasks on one list.
The `Tasks` page (`src/manifest.json`, type `index` over `caseTask`) SHALL
carry `quickFilters` chips All (no filter), Mine (`assignee = @me`,
`isTerminalStatus = false`) and Unclaimed (`assignee = "IS NULL"`,
`isTerminalStatus = false`), in that order, with All as the default
(decision D-default, revised — see `my-work`). The chips SHALL use the
same shape and the same behaviour as the chips on `Cases`, so a person who
learned one list has learned the other. The page SHALL keep its
saved views and its generic sidebar filters.

#### Scenario: All is the default lens on tasks, Mine is one click away
@e2e tests/e2e/case-list-lenses.spec.ts

- **GIVEN** an open task assigned to the signed-in user and an open task assigned to another user
- **WHEN** you open the Tasks page
- **THEN** the chip All SHALL be active and the list SHALL show both tasks
- **AND** choosing the chip Mine SHALL show your task and SHALL NOT show the other user's task

#### Scenario: Unclaimed shows tasks nobody holds
@e2e tests/e2e/case-list-lenses.spec.ts

- **GIVEN** an open task with no assignee and a completed task with no assignee
- **WHEN** you choose the chip Unclaimed
- **THEN** the list SHALL show the open task and SHALL NOT show the completed one

#### Scenario: All shows every task
@e2e tests/e2e/case-list-lenses.spec.ts

- **GIVEN** an open task assigned to another user and a completed task
- **WHEN** you choose the chip All
- **THEN** the list SHALL show both tasks
