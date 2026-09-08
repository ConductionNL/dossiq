---
status: in-progress
---

## OpenSpec changes

- `case-flow-human-steps` (active) — a status move performed by a flow is
  attributable to the node that performed it, and each move in a case flow is
  its own step rather than a side effect of another one: the status is the
  applicant-facing signal, so it must be moved deliberately.

## Purpose

@e2e exclude Status transition guard engine is V1; guard evaluation logic is covered by unit tests, not browser E2E.

## Requirements

### Requirement: Guard Evaluation Engine

The system SHALL evaluate all guards on a status transition before allowing the transition to proceed. Guards are evaluated in the frontend by querying the case's current state against the guard conditions.

**Feature tier**: V1

#### Scenario: Checklist guard evaluation

- **WHEN** a transition has a checklist guard with 5 items
- **AND** the case handler has checked 4 of 5 items
- **THEN** the transition button SHALL be disabled
- **AND** the tooltip SHALL display: "1 checklistitem niet afgevinkt: 'Besluit opgesteld'"

#### Scenario: Required field guard evaluation

- **WHEN** a transition requires field "resultaat" to be filled
- **AND** the case has no value for "resultaat"
- **THEN** the transition button SHALL be disabled
- **AND** the system SHALL highlight the missing field in the case form

#### Scenario: Required document guard evaluation

- **WHEN** a transition requires document type "Besluit" to be uploaded
- **AND** no document of type "Besluit" is attached to the case
- **THEN** the transition button SHALL be disabled
- **AND** the system SHALL display: "Vereist document ontbreekt: Besluit"

#### Scenario: Role guard evaluation

- **WHEN** a transition is restricted to role "Afdelingshoofd"
- **AND** the current user has role "Behandelaar" on the case
- **THEN** the transition button SHALL NOT be visible to this user

#### Scenario: All guards pass

- **WHEN** all guards on a transition are satisfied
- **THEN** the transition button SHALL be enabled
- **AND** clicking it SHALL execute the status change and trigger any configured automatic actions

### Requirement: Transition Execution

The system SHALL execute status transitions atomically: the case status changes, automatic actions are triggered, and an audit trail entry is created in a single logical operation.

**Feature tier**: V1

#### Scenario: Successful transition with audit trail

- **WHEN** a case handler executes transition "Afronden" from "In behandeling" to "Afgehandeld"
- **THEN** the case `status` property SHALL be updated to the target StatusType UUID
- **AND** an audit entry SHALL be created with: timestamp, user, fromStatus, toStatus, transitionLabel
- **AND** the case `updatedAt` timestamp SHALL be refreshed

#### Scenario: Transition triggers automatic actions

- **WHEN** transition "Goedkeuren" has automatic actions configured (send email, create task)
- **AND** the transition is executed
- **THEN** all automatic actions SHALL be triggered in the order they are defined
- **AND** failure of an automatic action SHALL NOT roll back the status change
- **AND** failed actions SHALL be logged with error details

### Requirement: Available Transitions for Current User

The system SHALL compute and display only the transitions available to the current user based on their role, the current case status, and guard satisfaction.

**Feature tier**: V1

#### Scenario: Display available transitions on case detail

- **WHEN** a case handler views case detail for a case in status "In behandeling"
- **AND** the workflow defines transitions "Goedkeuren" (requires role Afdelingshoofd) and "Terugsturen" (any role)
- **AND** the user has role "Behandelaar"
- **THEN** only the "Terugsturen" button SHALL be displayed

#### Scenario: No transitions available

- **WHEN** a case is in a final status "Afgehandeld"
- **THEN** no transition buttons SHALL be displayed
- **AND** the case status area SHALL indicate "Zaak is afgehandeld"

### Requirement: A status brings its checklist tasks with it (REQ-STE-01)

Every status brings its checklist tasks with it when a case reaches it. The
`statusType` schema SHALL carry `checklist`, a list of items with a `title`
and a `required` flag. When a case enters a status, over a transition or an
admin's free-form move, the engine SHALL create one task per item on the
case through the existing `createTask` handler, with `workflowStepId` set to
the status type's id. The tasks SHALL show in the Tasks pane on the case
page.

**Feature tier**: MVP

#### Scenario: The tasks arrive with the status
@e2e tests/e2e/checklist-per-status.spec.ts

- **GIVEN** a case type whose status In behandeling lists two checklist items
- **AND** a case of that type in Intake with no tasks
- **WHEN** you move the case to In behandeling
- **THEN** the Tasks pane SHALL show two tasks with the items' titles
- **AND** each SHALL be at status available

#### Scenario: An admin's free-form move brings them too
@e2e tests/e2e/checklist-per-status.spec.ts

- **GIVEN** the same case type and a case in Intake
- **WHEN** an admin moves the case to In behandeling with the free-form move
- **THEN** the Tasks pane SHALL show the two tasks

#### Scenario: A status without a list creates nothing
@e2e exclude The absence of a task is asserted by StatusChecklistTest; the browser cannot tell nothing from not yet.

- **GIVEN** a status whose `checklist` is empty or absent
- **WHEN** a case enters it
- **THEN** the engine SHALL create no task
- **AND** the transition's own automatic actions SHALL run as before

### Requirement: Entering a status twice creates the tasks once (REQ-STE-02)

You send a case back and forward without getting its tasks twice. Before
creating an item's task the engine SHALL look for a task on the case with the
same title and the status type's id as `workflowStepId`, open or completed,
and SHALL skip the item when one exists.

**Feature tier**: MVP

#### Scenario: Back to Intake and forward again
@e2e tests/e2e/checklist-per-status.spec.ts

- **GIVEN** a case that reached In behandeling and holds its two checklist tasks, one completed
- **WHEN** you move the case back to Intake and forward to In behandeling
- **THEN** the Tasks pane SHALL still show two tasks for that status
- **AND** the completed one SHALL still be completed

### Requirement: A required item holds the case in its status (REQ-STE-03)

A required item keeps the case where it is until its task is done. On every
transition out of a status the engine SHALL evaluate a `statusChecklist`
guard: each item of the current status with `required` true SHALL have a
task on the case at status completed. A required item with no task SHALL
count as not done. The failed guard SHALL name the item, and the case page
SHALL show that reason on the transition button.

**Feature tier**: MVP

#### Scenario: The button says which item is open
@e2e tests/e2e/checklist-per-status.spec.ts

- **GIVEN** a case in Intake whose required item Check the objection is on time has an open task
- **WHEN** you open the case page
- **THEN** the transition to In behandeling SHALL be disabled
- **AND** its reason SHALL read Checklist item not done: Check the objection is on time

#### Scenario: Completing the task frees the case
@e2e tests/e2e/checklist-per-status.spec.ts

- **GIVEN** the same case
- **WHEN** you complete that task
- **THEN** the transition to In behandeling SHALL be enabled
- **AND** the move SHALL succeed

#### Scenario: An optional item does not hold the case
@e2e tests/e2e/checklist-per-status.spec.ts

- **GIVEN** a case in Intake whose only open task is the optional item Confirm receipt to the objector
- **WHEN** you open the case page
- **THEN** the transition to In behandeling SHALL be enabled

#### Scenario: The guard fails server-side too
@e2e exclude A direct API call past the disabled button is asserted by StatusChecklistGuardTest and StatusTransitionServiceRouteSeamTest.

- **GIVEN** a case in Intake with a required item's task still open
- **WHEN** the transition is posted to the API
- **THEN** the engine SHALL refuse it with the `statusChecklist` guard failed
- **AND** the case SHALL still be in Intake
