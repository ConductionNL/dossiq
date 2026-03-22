## Requirements

### Requirement: Workflow Template Data Model

The system SHALL store workflow definitions as OpenRegister objects in the `procest` register under a `workflowTemplate` schema. A workflow template defines the ordered process steps, status transitions, guards, and automatic actions for a specific zaaktype. The model aligns with CMMN 1.1 CasePlanModel concepts and maps to ZGW Catalogi StatusType sequences.

**Feature tier**: V1

| Property | Type | CMMN Mapping | Description |
|----------|------|-------------|-------------|
| `title` | string | CasePlanModel name | Human-readable workflow name |
| `description` | string | -- | Purpose and usage notes |
| `caseType` | reference (UUID) | CaseDefinition | The zaaktype this workflow belongs to |
| `version` | integer | -- | Auto-incrementing version number |
| `isActive` | boolean | -- | Whether this is the active version |
| `isDraft` | boolean | -- | Draft templates cannot be used for new cases |
| `steps` | array of WorkflowStep | Stage[] | Ordered process steps |
| `transitions` | array of StatusTransition | Sentry[] | Allowed status transitions with guards |
| `createdAt` | datetime | -- | Creation timestamp |
| `updatedAt` | datetime | -- | Last modification timestamp |

#### Scenario: Create workflow template for a zaaktype

- **WHEN** an administrator creates a new workflow template for zaaktype "Omgevingsvergunning"
- **THEN** the template SHALL be stored as an OpenRegister object with `isDraft: true` and `version: 1`
- **AND** the template SHALL reference the zaaktype UUID in `caseType`

#### Scenario: Workflow template references existing status types

- **WHEN** a workflow template defines transitions between statuses
- **THEN** each status referenced in transitions SHALL correspond to a StatusType defined on the linked zaaktype
- **AND** the system SHALL validate referential integrity on save

### Requirement: Workflow Step Data Model

The system SHALL define workflow steps as embedded objects within a workflow template. Each step represents a unit of work within a process phase, aligned with CMMN HumanTask or ProcessTask concepts.

**Feature tier**: V1

| Property | Type | CMMN Mapping | Description |
|----------|------|-------------|-------------|
| `id` | string (UUID) | PlanItem ID | Unique step identifier |
| `title` | string | HumanTask name | Step display name |
| `description` | string | -- | Instructions for the handler |
| `status` | reference | Stage | Which status this step belongs to |
| `order` | integer | -- | Execution order within the status |
| `assigneeRole` | reference | -- | Which RoleType can execute this step |
| `isRequired` | boolean | -- | Whether the step must be completed before transition |
| `checklist` | array of ChecklistItem | -- | Items to verify before marking step complete |
| `automaticActions` | array of ActionRef | -- | Actions triggered on step completion |

#### Scenario: Step belongs to a status phase

- **WHEN** a step is created with `status` referencing StatusType "In behandeling"
- **THEN** the step SHALL appear in the workflow editor under that status phase
- **AND** the step SHALL be ordered by its `order` property relative to sibling steps

#### Scenario: Required step blocks status transition

- **WHEN** a step with `isRequired: true` is not yet completed
- **THEN** the status transition that exits that step's status phase SHALL be blocked

### Requirement: Status Transition Data Model

The system SHALL define status transitions as embedded objects within a workflow template. Each transition defines a valid path between two statuses with optional pre-conditions (guards).

**Feature tier**: V1

| Property | Type | CMMN Mapping | Description |
|----------|------|-------------|-------------|
| `id` | string (UUID) | Sentry ID | Unique transition identifier |
| `fromStatus` | reference | Exit criterion source | Source status |
| `toStatus` | reference | Entry criterion target | Target status |
| `label` | string | -- | Transition button label (e.g., "Goedkeuren") |
| `guards` | array of Guard | OnPart/IfPart | Pre-conditions |
| `automaticActions` | array of ActionRef | -- | Actions triggered on transition |
| `allowedRoles` | array of reference | -- | Which RoleTypes may trigger this transition |

Guard types:
- `checklist`: All checklist items must be checked
- `requiredField`: Specific case fields must be filled
- `requiredDocument`: Specific document types must be uploaded
- `roleGuard`: User must have specific role on the case
- `customExpression`: JSONPath expression that must evaluate to true

#### Scenario: Transition with all guards met

- **WHEN** a case handler triggers transition "Goedkeuren" from "In behandeling" to "Afgehandeld"
- **AND** all guards (checklist complete, required documents uploaded, handler has role "behandelaar") are satisfied
- **THEN** the transition SHALL proceed and the case status SHALL change to "Afgehandeld"

#### Scenario: Transition with unmet guards

- **WHEN** a case handler triggers transition "Goedkeuren" but the required document "Besluit" is not uploaded
- **THEN** the transition SHALL be blocked
- **AND** the system SHALL display: "Kan niet doorgaan: document 'Besluit' is vereist"
