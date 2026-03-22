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

### Requirement: Pre-Seeded Bezwaar Workflow Template

The system SHALL provide a pre-seeded workflow template for the Bezwaar case type that encodes the AWB-mandated process steps, transitions, and guards. The template SHALL be imported via the repair step alongside the bezwaar case type.

**Feature tier**: V1

The bezwaar workflow template SHALL define the following transitions:

| From Status | To Status | Label | Guards |
|-------------|-----------|-------|--------|
| Ontvangen | Ontvankelijkheidstoets | Start toets | roleGuard: Behandelaar bezwaar |
| Ontvankelijkheidstoets | In behandeling | Ontvankelijk | requiredField: isTimely assessment |
| Ontvankelijkheidstoets | Niet-ontvankelijk | Niet-ontvankelijk verklaren | requiredField: dispositionDetails |
| In behandeling | Hoorzitting gepland | Hoorzitting plannen | -- |
| In behandeling | Advies uitgebracht | Hoorrecht afgezien | requiredField: hearingWaived=true |
| Hoorzitting gepland | Hoorzitting afgerond | Hoorzitting afronden | requiredField: minutesSummary |
| Hoorzitting afgerond | Advies uitgebracht | Advies uitbrengen | requiredField: advisoryReport |
| In behandeling | Beslissing op bezwaar | Direct beslissen | roleGuard: Beslisser (when no committee) |
| Advies uitgebracht | Beslissing op bezwaar | Beslissing nemen | requiredField: dispositionType, dispositionDetails |
| Beslissing op bezwaar | Afgehandeld | Afronden | checklist: decision letter sent, rechtsmiddelenclausule included |
| Any active | Ingetrokken | Intrekken | requiredField: withdrawal reason |

The workflow template SHALL include workflow steps for each status phase:

| Status Phase | Steps |
|-------------|-------|
| Ontvangen | Registreer bezwaarschrift, Controleer volledigheid, Bevestig ontvangst |
| Ontvankelijkheidstoets | Toets termijn, Toets belanghebbendheid, Toets besluit-karakter |
| In behandeling | Stel dossier samen, Informeer primair beslisser, Plan hoorzitting of registreer afzien |
| Hoorzitting gepland | Verstuur uitnodigingen, Bereid hoorzitting voor |
| Hoorzitting afgerond | Maak verslag, Deel verslag met partijen |
| Advies uitgebracht | Stel advies op, Deel advies met bestuursorgaan |
| Beslissing op bezwaar | Neem beslissing, Stel besluit op, Verstuur besluit met rechtsmiddelenclausule |

#### Scenario: Bezwaar workflow template is seeded after repair

- **WHEN** the Procest app repair step runs
- **THEN** a workflow template SHALL exist for the Bezwaar case type
- **AND** the template SHALL contain all defined transitions with their guards
- **AND** the template SHALL contain all defined steps per status phase
- **AND** the template SHALL be published (isDraft: false, isActive: true)

#### Scenario: Administrator can customize the bezwaar workflow

- **WHEN** an administrator opens the Bezwaar case type in the admin settings
- **THEN** the pre-seeded workflow template SHALL be visible in the workflow tab
- **AND** the administrator SHALL be able to create a new version to customize steps and transitions
- **AND** the original pre-seeded template SHALL remain as a base version

### Requirement: Pre-Seeded Beroep Workflow Template

The system SHALL provide a pre-seeded workflow template for the Beroep case type with transitions for tracking court proceedings.

**Feature tier**: V1

| From Status | To Status | Label | Guards |
|-------------|-----------|-------|--------|
| Beroep ontvangen | Verweerschrift in voorbereiding | Start verweer | roleGuard: Behandelaar |
| Verweerschrift in voorbereiding | Verweerschrift ingediend | Verweer indienen | requiredDocument: Verweerschrift |
| Verweerschrift ingediend | Zitting gepland | Zitting plannen | -- |
| Zitting gepland | Zitting afgerond | Zitting afronden | -- |
| Zitting afgerond | Uitspraak ontvangen | Uitspraak registreren | requiredField: ruling outcome |
| Uitspraak ontvangen | Afgehandeld | Afronden | -- |
| Any active | Ingetrokken | Intrekken | -- |
| Any active | Schikking | Schikking treffen | requiredField: settlement details |

#### Scenario: Beroep workflow template is seeded after repair

- **WHEN** the Procest app repair step runs
- **THEN** a workflow template SHALL exist for the Beroep case type
- **AND** the template SHALL contain all defined transitions
- **AND** the template SHALL be published (isDraft: false, isActive: true)
