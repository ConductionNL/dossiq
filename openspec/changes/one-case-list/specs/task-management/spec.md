## ADDED Requirements

### Requirement: REQ-TASK-016 Task lenses MUST sit on the Tasks index

You switch between your tasks, unclaimed tasks and all tasks on one list.
The `Tasks` page (`src/manifest.json`, type `index` over `caseTask`) SHALL
carry `quickFilters` chips Mine (`assignee = @me`, `isTerminalStatus =
false`), Unclaimed (`assignee = "IS NULL"`, `isTerminalStatus = false`) and
All (no filter), in that order, with Mine as the default. The chips SHALL
use the same shape and the same behaviour as the chips on `Cases`, so a
person who learned one list has learned the other. The page SHALL keep its
saved views and its generic sidebar filters.

#### Scenario: Mine is the default lens on tasks
@e2e tests/e2e/case-list-lenses.spec.ts

- **GIVEN** an open task assigned to the signed-in user and an open task assigned to another user
- **WHEN** you open the Tasks page
- **THEN** the chip Mine SHALL be active
- **AND** the list SHALL show your task and SHALL NOT show the other user's task

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
