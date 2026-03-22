## ADDED Requirements

### Requirement: Process Step CRUD within Workflow

The system SHALL allow administrators to create, edit, reorder, and delete process steps within a workflow status phase. Steps represent concrete work items (CMMN HumanTask) that handlers must complete during that phase.

**Feature tier**: V1

#### Scenario: Add a step to a status phase

- **WHEN** an administrator clicks "Stap toevoegen" within the "In behandeling" status node
- **THEN** a new step SHALL be created with default values (title: "Nieuwe stap", isRequired: false, order: last)
- **AND** the step configuration panel SHALL open for editing

#### Scenario: Edit step properties

- **WHEN** an administrator edits step "Toets ontvankelijkheid" and sets `isRequired: true` and adds 3 checklist items
- **THEN** the step SHALL be saved to the workflow template
- **AND** the visual editor SHALL show a badge indicating "3 checks" on the step

#### Scenario: Delete a step

- **WHEN** an administrator deletes step "Optionele controle" from status "Intake"
- **THEN** the step SHALL be removed from the workflow template
- **AND** remaining steps SHALL have their `order` properties recalculated

#### Scenario: Reorder steps via drag-and-drop

- **WHEN** an administrator drags step at position 3 to position 1
- **THEN** all affected steps SHALL have their `order` updated
- **AND** the visual order in the editor SHALL reflect the change immediately

### Requirement: Step-to-Task Mapping at Runtime

The system SHALL automatically create tasks for case handlers based on the workflow steps defined for the current status. When a case enters a status with configured steps, tasks are created from the step definitions.

**Feature tier**: V1

#### Scenario: Case enters status with steps

- **WHEN** case "ZK-2024-001" transitions to status "In behandeling" which has 3 configured steps
- **THEN** the system SHALL create 3 tasks linked to the case, one per step
- **AND** each task SHALL inherit: title, description, assigneeRole, checklist from the step definition
- **AND** tasks SHALL have status `available` per CMMN lifecycle

#### Scenario: Required steps block status exit

- **WHEN** status "In behandeling" has 2 required steps and 1 optional step
- **AND** the handler has completed 1 required step and the optional step
- **THEN** the exit transitions from "In behandeling" SHALL remain blocked
- **AND** the UI SHALL display: "1 verplichte stap nog niet afgerond: 'Inhoudelijke beoordeling'"

#### Scenario: Optional steps do not block transition

- **WHEN** all required steps in status "Intake" are completed but 1 optional step remains
- **THEN** the exit transitions from "Intake" SHALL be available
- **AND** the optional step task SHALL be automatically terminated when the transition executes
