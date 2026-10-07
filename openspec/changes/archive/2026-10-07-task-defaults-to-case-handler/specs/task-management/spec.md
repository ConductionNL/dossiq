## ADDED Requirements

### Requirement: REQ-TASK-019 A task on a case defaults to the case handler

When a transition or a flow step creates a task and its authored assignee
and fallback both name nobody, the task SHALL be assigned to the case's
`assignee`; when the case has none, to the case's `assignedGroup`; only
when both are empty SHALL the task be unassigned. An action with
`assignee: "none"` SHALL create an unassigned task regardless.

#### Scenario: The handler gets the task
@e2e tests/e2e/task-defaults-to-case-handler.spec.ts

- **GIVEN** a case assigned to you and a transition whose action names no assignee
- **WHEN** the transition runs
- **THEN** the created task SHALL be assigned to you
- **AND** it SHALL appear under Mine on Tasks

#### Scenario: The team gets the task when there is no handler
@e2e exclude covered by AssigneeResolverTest::testFallsBackToAssignedGroup over a case fixture

- **GIVEN** a case with no assignee and team Permits
- **WHEN** a task is created without an authored assignee
- **THEN** the task SHALL carry team Permits as its assignee group

#### Scenario: An action opts out
@e2e exclude covered by AssigneeResolverTest::testNoneStaysUnassigned

- **GIVEN** an action with `assignee: "none"` on an assigned case
- **WHEN** the task is created
- **THEN** it SHALL have no assignee
