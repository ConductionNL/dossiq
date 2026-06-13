# Design: workflow-engine-enhancement

## Context

Current Procest workflow implementation:
- Case status lifecycle defined per caseType with discrete statusTypes
- Process steps and task routing are hardcoded in caseType configuration
- Status transitions exist but lack pre-condition gating (checklists, required fields, role guards)
- Automatic actions (email, task creation) are not configurable by administrators
- No visual workflow editor — all configuration requires developer intervention

After this change, the workflow definition model becomes first-class:

```
CaseType
  ├── WorkflowTemplate (active version for new cases)
  │   ├── WorkflowStep (process steps)
  │   │   ├── assigneeRole (roleType)
  │   │   ├── checklist[] (items to complete before exit)
  │   │   └── automaticActions[] (email, task creation, etc)
  │   └── StatusTransition (permitted state changes)
  │       ├── fromStatus → toStatus
  │       ├── guards[] (checklist, requiredField, requiredDocument, roleGuard)
  │       ├── allowedRoles[] (who can execute)
  │       └── automaticActions[] (email, task, sub-case creation)
  └── WorkflowTemplate (historical versions, immutable)

Case
  ├── workflowTemplate (reference to bound template, set at creation time)
  ├── workflowVersion (version of the template at case creation)
  └── statusHistory[] (list of status + transition metadata)
```

## The WorkflowTemplate Data Structure

### Core Fields

- `id` (UUID, auto-generated)
- `title` (string) — workflow name
- `description` (string) — purpose and usage notes
- `caseType` (UUID ref) — which zaaktype this workflow belongs to
- `version` (integer) — auto-incrementing version (1, 2, 3, ...)
- `isActive` (boolean) — whether this is the active version for new cases
- `isDraft` (boolean) — draft templates cannot be used for new cases
- `validFrom` (date) — when this version becomes active
- `validUntil` (date) — when this version expires (null = indefinite)
- `parentWorkflow` (UUID ref, optional) — for workflow inheritance (Enterprise tier)

### Process Steps (JSON array)

Each step object:
- `id` (UUID)
- `title` (string) — step name (e.g. "Verify Applicant")
- `description` (string) — step purpose
- `status` (UUID ref to statusType) — which status this step belongs to
- `order` (integer) — position within the status (1, 2, 3, ...)
- `assigneeRole` (UUID ref to roleType, optional) — which role executes this step
- `isRequired` (boolean) — must this step complete before status exit
- `checklist` (array of {id, label, description}) — checklist items to verify
- `automaticActions` (array of ActionRef) — actions triggered on step completion

### Status Transitions (JSON array)

Each transition object:
- `id` (UUID)
- `fromStatus` (UUID ref)
- `toStatus` (UUID ref)
- `label` (string) — display label ("Approve", "Reject", "Request More Info", ...)
- `guards` (array of Guard objects)
  - Guard types: `checklist`, `requiredField`, `requiredDocument`, `roleGuard`
  - Each guard specifies: `type`, `config` (field name, document type, role, etc)
- `allowedRoles` (array of UUID refs to roleType) — who can initiate this transition
- `automaticActions` (array of ActionRef) — actions triggered on transition
  - Action types: `sendEmail`, `createTask`, `createSubCase`, `webhook`, `setField`, `notify`
  - Each action specifies: `type`, `config` (recipient, template, fields, ...)

### Visual Editor Canvas

- `nodePositions` (JSON map of statusType UUID → {x, y}) — canvas layout (persisted for round-trip editing)

## Seed Data

Three example workflows (Dutch terminology):

### Example 1: Simple Omgevingsvergunning Workflow

**Case Type:** Omgevingsvergunning (environmental permit)

**Workflow:** "Standaard Omgevingsvergunning"
- Version: 1
- Status Lifecycle:
  1. `Ontvangen` (Received) → steps: register application, check completeness
  2. `In Behandeling` (In Progress) → steps: technical review, public consultation, decision preparation
  3. `Besloten` (Decided) → steps: issue permit or rejection letter
  4. `Afgehandeld` (Closed) → steps: archive case

**Key Transitions:**
- `Ontvangen` → `In Behandeling`: Guard: checklist (all intake items), Guard: requiredDocument (site plan)
- `In Behandeling` → `Besloten`: Guard: roleGuard (only Behandelaar role)
- `Besloten` → `Afgehandeld`: automaticAction: sendEmail (notify applicant)

### Example 2: VTH Multi-Step Workflow (Template for Follow-up)

**Case Type:** VTH Bezwaar (Objection to VTH decision)

**Workflow:** "Standaard VTH Bezwaar Behandeling"
- Version: 1
- Status Lifecycle:
  1. `Bezwaar Ontvangen` → steps: register objection, assess timeliness, assign to evaluator
  2. `Hoorzitting Gepland` → steps: schedule hearing, send invitations, prepare minutes
  3. `Advies Voorbereiding` → steps: prepare advisory report, coordinate with committee
  4. `Advies Gegeven` → steps: share advice with parties, prepare decision
  5. `Beslissing Genomen` → steps: finalize decision, send decision letter

### Example 3: Delegation + Inheritance

**Case Type:** Beroep (Appeal) — inherits from Bezwaar

**Workflow:** "Beroep bij Rechtbank"
- Parent Workflow: VTH Bezwaar
- Overrides: step "Hoorzitting Gepland" with different timeline, step "Advies Voorbereiding" includes legal review

## File-by-File Implementation Plan

### Backend (lib/, app routing, API)

#### lib/Settings/procest_register.json — ADD workflowTemplate schema

Add new OR schema definitions:

```json
"workflowTemplate": {
  "title": "Workflow Template",
  "type": "object",
  "properties": {
    "title": { "type": "string" },
    "description": { "type": "string" },
    "caseType": { "type": "string" },
    "version": { "type": "integer" },
    "isActive": { "type": "boolean" },
    "isDraft": { "type": "boolean" },
    "validFrom": { "type": "string", "format": "date" },
    "validUntil": { "type": "string", "format": "date", "nullable": true },
    "parentWorkflow": { "type": "string", "nullable": true },
    "steps": { "type": "string" },
    "transitions": { "type": "string" },
    "nodePositions": { "type": "string" }
  }
}
```

#### lib/Service/WorkflowEngineService.php — NEW

Core service for workflow operations:
- `getWorkflowTemplate($caseTypeId)` — fetch active template for a case type
- `getWorkflowVersion($caseTypeId, $version)` — fetch specific version
- `getAvailableTransitions($case)` — which transitions are available given current state
- `evaluateGuards($transition, $case)` — evaluate all guards; return unmet conditions
- `executeTransition($case, $transitionId)` — perform status change + automatic actions
- `createWorkflowTemplate($data)` — create new template version

#### lib/Controller/WorkflowController.php — NEW

REST API endpoints:
- `GET /api/workflows/{caseType}` — fetch active workflow
- `GET /api/workflows/{caseType}/{version}` — fetch specific version
- `POST /api/workflows/{caseType}/versions` — create new version (draft)
- `PATCH /api/workflows/{caseType}/versions/{version}` — update draft
- `POST /api/workflows/{caseType}/versions/{version}/activate` — publish version as active
- `POST /api/workflows/{caseType}/export` — export as JSON
- `POST /api/workflows/import` — import from JSON

#### lib/Listener/WorkflowTransitionListener.php — NEW

On case status change:
1. Load case's bound workflowTemplate + version
2. Load transition definition
3. Execute automatic actions (sendEmail, createTask, createSubCase, webhook, setField, notify)
4. Update statusHistory

### Frontend (src/views/, src/components/)

#### src/views/WorkflowEditor.vue — NEW

Visual drag-and-drop workflow editor:
- Canvas: drag statusType nodes, draw transition arrows
- Step panel: define steps within each status (checklist items, assignee role, actions)
- Transition panel: define guards (checklist, required fields/documents, roles) and actions
- Validation: ensure all transitions have at least one target; all steps belong to a status
- Export button: download workflow as JSON
- Publish button: activate this version for new cases

#### src/components/WorkflowStepEditor.vue — NEW

Edit a single step:
- Title, description
- Assign to role
- Add/edit checklist items
- Configure automatic actions (email template, task title, sub-case settings, webhook URL)
- Mark as required/optional

#### src/components/WorkflowTransitionEditor.vue — NEW

Edit a single transition:
- Source and target status
- Label (displayed in UI)
- Add guards (checklist items, required fields, required documents, role restrictions)
- Add automatic actions (same as step actions)

#### src/components/WorkflowGuardBuilder.vue — NEW

Configure guards for a transition:
- Checklist: select which checklist items must be completed
- Required field: select which custom properties must be populated
- Required document: select which document types must be attached
- Role guard: select which roles are allowed to execute this transition

#### src/components/WorkflowActionBuilder.vue — NEW

Configure automatic actions:
- Email action: select template, optionally override recipient/subject
- Create task action: enter task title, due date offset, assignee role
- Create sub-case action: select sub-case type, inherit parent properties
- Webhook action: enter URL, optional payload template
- Set field action: select field, enter/derive value
- Notify action: select notification type (in-app, dashboard, etc)

### Admin Settings

#### src/views/admin/WorkflowManagement.vue — NEW

Admin panel for workflow management:
- List all workflows per case type
- Show active version
- Show version history (draft, active, expired)
- Button to edit workflow (open editor)
- Button to delete draft versions
- Import/export buttons

## Backwards Compatibility

- Existing cases with no `workflowTemplate` reference will continue to work if a fallback "legacy" workflow is created for their case type
- New cases always require an active workflowTemplate (enforced at case creation)
- WorkflowTemplate versioning ensures running cases preserve their original workflow

## Entity Relationships

From ADR-000 (data model):

- `workflowTemplate.caseType` → `caseType.id` (many-to-one)
- `case.workflowTemplate` → `workflowTemplate.id` (many-to-one)
- `case.status` → `statusType.id` (many-to-one)
- `workflowTemplate.steps[].status` → `statusType.id` (array of refs)
- `workflowTemplate.transitions[].fromStatus` → `statusType.id` (array of refs)
- `workflowTemplate.transitions[].toStatus` → `statusType.id` (array of refs)
- `workflowTemplate.steps[].assigneeRole` → `roleType.id` (array of refs)
- `workflowTemplate.transitions[].allowedRoles[]` → `roleType.id` (array of refs)
- Automatic actions reference templates: email template ID, document type ID, etc

## Related ADRs

- **ADR-000** (data model) — workflowTemplate, workflowStep entity definitions
- **ADR-001** (data layer) — all workflow data stored in OpenRegister
- **ADR-005** (security) — role-based access to steps enforced by OR RBAC
- **ADR-003** (backend) — WorkflowEngineService patterns, listeners
- **ADR-004** (frontend) — Vue component patterns, drag-and-drop libraries
- **ADR-008** (testing) — integration tests for guard evaluation, automatic actions
