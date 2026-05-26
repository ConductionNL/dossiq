# Proposal: workflow-engine-enhancement

## Why

Dutch municipalities require fully configurable, zero-code workflow definitions for case types (zaaktypen). Market intelligence shows:
- **1,022** requirements for zaaktype configuration
- **603** requirements for no-code/zero-coding configuration  
- **965** requirements for drag-and-drop interface
- **~534 unique tenders** across all clusters

Current Procest implementation forces developers to hard-code process steps, status transitions, and task routing. Functional administrators cannot modify workflows independently. Tenders explicitly mandate: "Zaaktypen kunnen zelfstandig en zonder tussenkomst van de Opdrachtnemer op basis van zero coding volledig worden ingericht" (case types can be configured independently and without developer involvement using zero coding).

This is the foundational change that enables VTH (Vergunningen, Toezicht, Handhaving), Bezwaar/beroep, and Besluitvorming as follow-up workflow configurations rather than separate feature builds.

## What

Enhance Procest's workflow engine to support fully configurable zaaktype workflows through a visual drag-and-drop interface. Functional administrators can:

1. **Define process steps** — ordered workflow stages with assigned tasks and automatic actions
2. **Configure status transitions** — define permitted state changes with pre-conditions (checklists, required fields, role guards)
3. **Set automatic actions** — trigger emails, task creation, sub-case creation on status transitions
4. **Route by role** — configure which roles can execute which steps and transitions
5. **Version workflows** — running cases keep their workflow version; new cases use the latest
6. **Import/export workflows** — move definitions between environments without developer help

All configuration occurs in a visual editor with no code required.

## Capabilities

### New Capabilities

- `workflow-definition-engine`: Define configurable workflow templates per zaaktype with process steps, status transitions, guards, and automatic actions
- `workflow-visual-editor`: Drag-and-drop interface for building workflows (status nodes, transition arrows, conditions, actions)
- `workflow-versioning`: Running cases preserve their workflow version; new cases use the latest version
- `workflow-import-export`: Export workflow definitions to JSON; import to same or different environments

### Related Capabilities

- `role-based-step-routing` (existing spec) — step routing decisions enforced per defined workflows
- `task-management` (existing spec) — tasks created by workflow automatic actions
- `case-status-transitions` (existing spec) — status transitions gated by workflow pre-conditions

## Affected Projects

- [x] Project: `procest` — all implementation work in this repo
- [x] Project: `openregister` — workflow definitions stored as OR objects with schema validation
- Reference: `procest/openspec/specs/case-types/` — existing zaaktype/status model
- Reference: `procest/openspec/specs/task-management/` — task creation within workflows
- Reference: `CMMN 1.1` — workflow model aligns with CMMN concepts (CasePlanModel, stages, tasks, milestones)

## Scope

### In Scope

- Workflow definition model (process steps, transitions, guards, actions) stored as OR objects
- Visual workflow editor (drag-and-drop UI for building workflows)
- Status transition engine with configurable pre-conditions (checklists, required fields, role guards)
- Process step configuration (ordered steps, assignable tasks, automatic actions)
- Zaaktype versioning (running cases preserve workflow version; new cases use latest)
- Workflow import/export (move definitions between OTAP environments)
- Automatic actions (send emails, create tasks, create sub-cases, set fields, notify)
- Role-based step routing (configure which roles can execute which steps/transitions)

### Out of Scope (follow-up changes)

- VTH-specific workflow templates and domain logic (`vth-workflow-configuration`)
- Bezwaar/beroep workflow templates (`bezwaar-beroep-workflow`)
- Besluitvorming workflow templates (`besluitvorming-workflow`)
- Signalering/notification widgets (`signalering-widgets`)
- GIS integration (`gis-integration`)

### NOTE

VTH, Bezwaar/beroep, and Besluitvorming are follow-up changes that **configure** this engine with domain-specific workflows. They should not require new engine functionality — only workflow template definitions and potentially small domain-specific extensions (e.g., leges calculation hooks for VTH).

## Dependencies

- **OpenRegister**: Workflow definitions stored as OR objects with schema validation
- **Procest case-types spec**: Existing zaaktype/status model in `openspec/specs/case-types/`
- **Procest task-management spec**: Task creation/assignment within workflows
- **CMMN 1.1**: Workflow model should align with CMMN concepts

## Success Criteria

1. GIVEN a functional administrator, WHEN they open the workflow editor for a zaaktype, THEN they can visually define process steps and status transitions without writing code
2. GIVEN a workflow definition with pre-conditions on a status transition, WHEN a case handler attempts the transition without meeting all conditions, THEN the transition is blocked and unmet conditions are displayed
3. GIVEN a zaaktype with a configured workflow, WHEN a new case of that type is created, THEN the case follows the defined workflow with the correct initial status and available transitions
4. GIVEN a zaaktype whose workflow is updated, WHEN there are running cases of that type, THEN running cases continue with the previous workflow version while new cases use the updated version
5. GIVEN a workflow definition in a test environment, WHEN an administrator exports and imports it to production, THEN the workflow is transferred without requiring developer intervention
6. GIVEN a workflow step configured with an automatic email action, WHEN the status transition occurs, THEN the configured email is sent to the zaakklant
7. GIVEN a workflow step restricted to a specific role, WHEN a user without that role attempts to execute the step, THEN they are denied and see a clear explanation
8. GIVEN multiple zaaktypen, WHEN an administrator configures workflow inheritance, THEN child zaaktypen inherit parent workflow steps and can override specific steps
