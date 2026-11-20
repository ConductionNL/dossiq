## ADDED Requirements

### Requirement: A planned next action is typed, owned and chained (REQ-TDP-01)

A case SHALL be able to carry a planned next action: a typed record with an
owner, a date and a state. Completing it SHALL schedule the action its type
declares as the successor, and a type declaring no successor SHALL end the
chain. Actions SHALL be scheduled one at a time, not created in advance.

#### Scenario: Completing one action plans the next
@e2e tests/e2e/task-dependencies-and-the-next-planned-action.spec.ts

- **GIVEN** an action type whose successor is a follow-up call after ten days
- **WHEN** the current action is completed
- **THEN** a follow-up call SHALL be planned ten days later
- **AND** it SHALL carry the owner its type resolves

#### Scenario: A chain ends where the type says so
@e2e exclude unit; PlannedActionChainTest

- **GIVEN** an action type declaring no successor
- **WHEN** the action is completed
- **THEN** no further action SHALL be planned
- **AND** the case SHALL show that no next action is planned

#### Scenario: The case answers what happens next
@e2e tests/e2e/task-dependencies-and-the-next-planned-action.spec.ts

- **GIVEN** a case with a planned action
- **WHEN** the case is opened
- **THEN** the next planned action, its date and its owner SHALL be readable without opening the history

### Requirement: A task blocked by another is released by the system (REQ-TDP-02)

A task SHALL be able to declare the task that blocks it. The blocked state
SHALL be derived from that declaration and never set by hand. A blocked task
SHALL NOT appear in its assignee's due list while it holds. Closing the
blocker SHALL release every task it blocked, with no person acting.

#### Scenario: A blocked task stays out of the due list
@e2e tests/e2e/task-dependencies-and-the-next-planned-action.spec.ts

- **GIVEN** a task blocked by an open task
- **WHEN** its assignee opens their due list
- **THEN** the blocked task SHALL NOT be listed
- **AND** the case SHALL show it as blocked, naming the blocker

#### Scenario: Closing the blocker releases the task
@e2e tests/e2e/task-dependencies-and-the-next-planned-action.spec.ts

- **GIVEN** the same pair
- **WHEN** the blocking task is closed
- **THEN** the blocked task SHALL become due
- **AND** it SHALL appear in its assignee's due list without anybody editing it

#### Scenario: The blocked state cannot be set by hand
@e2e exclude unit; TaskBlockedStateTest

- **GIVEN** a task with no blocker
- **WHEN** a write sets its state to blocked
- **THEN** the write SHALL be refused
