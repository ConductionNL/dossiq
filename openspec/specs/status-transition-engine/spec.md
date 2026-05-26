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
