# workflow-definition-engine Specification

---
status: proposed
---

## Purpose

Enable functional administrators to define fully configurable zaaktype workflows (process steps, status transitions, guards, automatic actions) without developer involvement. The workflow engine SHALL enforce workflow definitions at the data layer, gating status transitions based on configurable pre-conditions and executing automatic actions on state changes.

This specification covers the workflow definition model, versioning, and execution semantics. Companion specs (`workflow-visual-editor`, `role-based-step-routing`, `workflow-import-export`) cover the UI and import/export mechanics.

## ADDED Requirements

### Requirement: Workflow Definition Model

A workflow definition SHALL consist of:
- Process steps (ordered tasks within a status)
- Status transitions (permitted state changes with guards and actions)
- Guards (checklist items, required fields, required documents, role restrictions)
- Automatic actions (email, task creation, sub-case creation, webhooks, field updates, notifications)

All workflow definitions SHALL be stored as OpenRegister objects with schema validation.

#### Scenario: Workflow template defines status lifecycle with steps and transitions

- GIVEN a workflow definition for zaaktype "Omgevingsvergunning"
- WHEN the definition specifies three statuses: "Ontvangen", "In Behandeling", "Afgehandeld"
- AND each status contains ordered process steps (e.g. Step 1: Check completeness, Step 2: Technical review)
- AND transitions between statuses define allowed state changes (e.g. Ontvangen → In Behandeling after checklist)
- THEN the workflow SHALL be storable as an OR workflowTemplate object
- AND the workflow SHALL be retrievable via API with all steps and transitions intact

#### Scenario: Process step configuration includes checklist items and assignee role

- GIVEN a step "Determine Dispute Admissibility" in a bezwaar workflow
- WHEN the step is configured with:
  - assigneeRole: "BezwaarBeoordelaar" (roleType UUID)
  - checklist items: ["Assess timeliness", "Check procedural requirements", "Assign evaluator"]
  - automaticActions: [sendEmail with template "request-info"]
- THEN the step SHALL be stored with all attributes
- AND when a case handler completes the checklist, automaticActions SHALL be triggered
- AND the step SHALL only be executable by users in the "BezwaarBeoordelaar" role

#### Scenario: Status transition with multiple guards requires all to be satisfied

- GIVEN a transition from "In Behandeling" to "Besloten" 
- WHEN the transition is configured with guards:
  - Guard 1: checklist (all decision-review items must be checked)
  - Guard 2: requiredField (decision-details field must be populated)
  - Guard 3: requiredDocument (rechtsmiddelen-clausule document must be attached)
- THEN the transition SHALL be blocked if any guard is unmet
- AND when all guards are satisfied, the transition SHALL be available
- AND the system SHALL clearly indicate which guards are unmet if transition is attempted

---

### Requirement: Workflow Versioning

Workflow templates MUST support versioning. Each zaaktype MAY have multiple workflow versions. One version is marked "active" for new cases. Running cases preserve the workflow version bound at their creation time.

#### Scenario: New case uses active workflow version; running case preserves original version

- GIVEN zaaktype "Bouwtoezicht" with active workflowTemplate version 1 (created 2026-02-01)
- WHEN a new case "BT-2026-001" is created
- THEN the case SHALL be bound to workflowTemplate version 1
- WHEN a new workflowTemplate version 2 is created and marked active (2026-03-15)
- AND a new case "BT-2026-002" is created
- THEN "BT-2026-002" SHALL be bound to version 2
- AND "BT-2026-001" SHALL continue to use version 1
- AND status transitions in "BT-2026-001" SHALL enforce version 1's guards and actions

#### Scenario: Draft and active versions coexist; only active version used for new cases

- GIVEN a workflow with version 1 (active, created 2026-02-01) and version 2 (draft, created 2026-03-15)
- WHEN the admin views available workflows for a case type
- THEN only version 1 SHALL be usable for new cases
- WHEN a case handler opens case "BT-2026-001" (bound to version 1)
- THEN the system SHALL allow transitions using version 1's definition
- WHEN the admin publishes version 2 as the new active version
- THEN new cases created after publication SHALL use version 2
- AND "BT-2026-001" SHALL continue using version 1 (no impact on running case)

#### Scenario: Workflow with validFrom/validUntil dates respects temporal validity

- GIVEN a workflow version 2 with validFrom: 2026-04-01, validUntil: 2026-06-30
- WHEN the current date is 2026-03-15 (before validFrom)
- THEN version 1 SHALL remain active for new cases
- WHEN the current date advances to 2026-04-01
- THEN version 2 SHALL automatically become active
- WHEN the current date reaches 2026-07-01 (after validUntil)
- THEN version 2 SHALL expire and version 1 SHALL revert to active (if no newer version exists)

---

### Requirement: Pre-Condition Gating on Status Transitions

A status transition MAY be guarded by pre-conditions. The system MUST evaluate all guards before allowing the transition. If any guard is unmet, the transition SHALL be blocked and the system SHALL report which conditions are unmet.

#### Scenario: Transition blocked until all checklist items are completed

- GIVEN a case in status "In Behandeling" with a transition "Complete Review → Advice Preparation"
- WHEN the transition has a guard: checklist (items: "technical-review", "public-consultation-summary", "impact-assessment")
- AND the checklist items are tracked as complete/incomplete on the case
- WHEN a case handler attempts the transition with only 2 of 3 items checked
- THEN the transition SHALL be blocked
- AND the system SHALL display: "Transition blocked: pending checklist items: impact-assessment"
- WHEN the case handler completes the third item
- THEN the transition SHALL become available

#### Scenario: Required field guard blocks transition until field is populated

- GIVEN a case with a custom field "applicant-address-verified" (boolean)
- WHEN a transition "Submit Application → Under Review" requires this field to be true
- AND the field is currently false or empty
- THEN the transition SHALL be blocked with message: "Transition blocked: required field not set: applicant-address-verified"
- WHEN the field is set to true
- THEN the transition SHALL become available

#### Scenario: Required document guard enforces document attachment

- GIVEN a case in status "Received" with a transition "Register Intake → Technical Review"
- WHEN the transition requires document type "Situated Plan" (situatietekening) to be attached
- AND no document of that type is attached to the case
- THEN the transition SHALL be blocked
- AND the system SHALL display: "Transition blocked: missing required document: Situated Plan"
- WHEN a "Situated Plan" document is uploaded to the case
- THEN the transition SHALL become available

#### Scenario: Role guard restricts transition to specific role(s)

- GIVEN a transition "Decide on Objection → Send Decision" in a bezwaar workflow
- WHEN the transition is guarded by: allowedRoles: ["Decision Maker", "Delegated Authority"]
- AND user "Jan" has role "Technical Reviewer"
- AND user "Piet" has role "Decision Maker"
- WHEN "Jan" attempts the transition
- THEN the transition SHALL be blocked
- AND the system SHALL display: "You do not have permission to execute this transition (required roles: Decision Maker, Delegated Authority)"
- WHEN "Piet" attempts the transition
- THEN the transition SHALL be available and executable

---

### Requirement: Automatic Actions on Status Transitions

A status transition MAY trigger automatic actions. The system MUST execute configured actions immediately upon successful status change. Supported action types: sendEmail, createTask, createSubCase, webhook, setField, notify.

#### Scenario: Email action sends configured message to zaakklant

- GIVEN a transition "Decision Made → Notify Applicant" with automaticAction: sendEmail
- WHEN the action is configured with:
  - template: "decision-letter" (email template)
  - recipient: "zaakklant-email" (from case)
  - subject: "Your application decision"
- AND a case handler completes the transition
- THEN the system SHALL:
  1. Render email template with case context
  2. Send email to zaakklant's registered email address
  3. Log the email send in case activity
- AND the email body SHALL include case-specific data (case ID, decision date, etc)

#### Scenario: Task creation action adds new task to case

- GIVEN a transition "In Treatment → Advice Needed" with automaticAction: createTask
- WHEN the action specifies:
  - taskTitle: "Prepare Advisory Report"
  - assigneeRole: "ExternalAdvisor" (roleType UUID)
  - dueDate: "+14 days" (relative to transition date)
  - description: "Evaluate technical feasibility and provide recommendations"
- AND a case handler executes the transition
- THEN the system SHALL:
  1. Create a new task object linked to the case
  2. Assign the task to users in the "ExternalAdvisor" role (or via task assignment queue)
  3. Set task dueDate to 14 days from now
  4. Set task status to "assigned"
- AND case activity log SHALL record task creation

#### Scenario: Sub-case creation action generates child case

- GIVEN a transition "Processing → Request Information" with automaticAction: createSubCase
- WHEN the action specifies:
  - subCaseType: "Information Request" (sub-zaaktype)
  - inheritParentProperties: true
  - initialStatus: "Sent to Applicant"
- AND a case handler executes the transition
- THEN the system SHALL:
  1. Create a new case of type "Information Request"
  2. Link it as a child case to the parent case
  3. Copy specified parent properties to the sub-case
  4. Set initial status to "Sent to Applicant"
- AND the parent case's relatedCases SHALL include the new sub-case UUID

#### Scenario: Webhook action posts transition event to external system

- GIVEN a transition "Final Decision → Notify Registry" with automaticAction: webhook
- WHEN the action specifies:
  - url: "https://ketenregister.example.nl/api/cases/update"
  - method: "POST"
  - payloadTemplate: "{ caseId, status, decision }"
  - authentication: "Bearer {API_KEY_SECRET}"
- AND a case handler executes the transition
- THEN the system SHALL:
  1. Render payloadTemplate with case context
  2. POST to the webhook URL with authentication header
  3. Log the webhook call (URL, timestamp, HTTP status)
  4. If webhook fails (non-2xx), log error and optionally notify admin
- AND the transition SHALL NOT be rolled back if webhook fails (fire-and-forget semantics)

#### Scenario: Set field action updates case property on transition

- GIVEN a transition "In Treatment → Complete → Decision Made" with automaticAction: setField
- WHEN the action specifies:
  - targetField: "completed-date"
  - value: "today" (or a formula)
- AND a case handler executes the transition
- THEN the system SHALL:
  1. Evaluate the value expression (can be: "today", "now", formula, static value)
  2. Update case.completed-date to the evaluated value
  3. Record field update in activity log with "system-triggered" attribution

#### Scenario: Notify action sends in-app/dashboard notification to user group

- GIVEN a transition "Advice Received → Review Advice → Decide" with automaticAction: notify
- WHEN the action specifies:
  - notificationType: "dashboard"
  - audience: "roleType:DT-Voorzitter" (specific role)
  - title: "New Advice Ready for Review"
  - message: "Advice received for case {caseId}. See details."
- AND a case handler executes the transition
- THEN the system SHALL:
  1. Create a notification object
  2. Deliver to all users with role "DT-Voorzitter"
  3. Display in user dashboard / notification center
  4. Mark as unread until user dismisses

---

### Requirement: Workflow Inheritance (Enterprise Tier)

Workflows MAY inherit from a parent workflow. Child workflows inherit all parent steps and transitions but MAY override specific steps. This enables zaaktype hierarchies (e.g. generic Bezwaar → VTH-specific Bezwaar).

#### Scenario: Child workflow inherits parent steps and transitions

- GIVEN a parent workflow "Generic Bezwaar" with steps: Register, Schedule Hearing, Prepare Advice, Make Decision
- WHEN a child workflow "VTH Bezwaar" sets parentWorkflow: "Generic Bezwaar"
- AND the child workflow specifies only two custom steps: "Prepare VTH-specific Report", "Legal Review"
- THEN the child workflow SHALL:
  1. Include all parent steps from "Generic Bezwaar"
  2. Add the two custom steps from "VTH Bezwaar"
  3. Preserve parent transitions unless explicitly overridden
- AND when a case of VTH Bezwaar type is created, it SHALL execute the merged workflow

#### Scenario: Child workflow can override parent step configuration

- GIVEN the parent "Generic Bezwaar" step "Schedule Hearing" has a default dueDate of +30 days
- WHEN the child "VTH Bezwaar" workflow overrides this step with dueDate: +14 days
- THEN cases of type "VTH Bezwaar" SHALL use the 14-day deadline
- AND cases of type "Generic Bezwaar" SHALL continue using 30-day deadline

---

### Requirement: Workflow Execution Model

When a case transitions between statuses, the system MUST:
1. Load the case's bound workflowTemplate + version
2. Evaluate all guards on the transition
3. If all guards pass, perform the status change
4. Execute all automaticActions in order
5. Record the status change in case.statusHistory with transition metadata

#### Scenario: Case status transition follows workflow definition steps

- GIVEN a case "OM-2026-0042" of type "Omgevingsvergunning" bound to workflowTemplate version 3
- WHEN the case is in status "Ontvangen"
- AND a case handler attempts to transition to "In Behandeling"
- THEN the system SHALL:
  1. Load case.workflowTemplate and case.workflowVersion
  2. Find the transition definition in the workflow
  3. Evaluate all guards (checklist, required fields, documents, roles)
  4. If all pass: perform the transition
  5. Execute automaticActions (in order)
  6. Update case.status to "In Behandeling"
  7. Append entry to case.statusHistory: { fromStatus, toStatus, timestamp, transitionId, actor }
- AND subsequent calls SHALL reflect the new status
- AND activity log SHALL record the transition with actor and timestamp

---

### Requirement: Workflow Export and Import

Workflow definitions SHALL be exportable to a portable JSON format and importable to the same or different environment. Export/import MUST preserve all workflow semantics (steps, transitions, guards, actions, versioning metadata).

#### Scenario: Workflow exports to JSON; imports with full fidelity

- GIVEN a workflow "Omgevingsvergunning - Versie 2" with 4 statuses, 12 steps, 8 transitions
- WHEN an admin exports the workflow as JSON
- THEN the export SHALL include:
  - Workflow metadata (title, version, validFrom/Until, parentWorkflow)
  - All steps with checklist items, assignee roles, automatic actions
  - All transitions with guards (checklist, requiredField, requiredDocument, roleGuard)
  - Automatic actions with full config (email templates, task titles, webhook URLs)
  - Canvas layout (nodePositions)
- WHEN the exported JSON is imported to a different environment
- THEN a new workflowTemplate SHALL be created with identical configuration
- AND all references to caseType and roleType UUIDs SHALL be mapped to the target environment
- AND the imported workflow SHALL be functional immediately (no additional setup required)

#### Scenario: Workflow import handles missing references gracefully

- GIVEN an export file referencing roleType UUID "abc-123" (which does not exist in target environment)
- WHEN the import is attempted
- THEN the system SHALL:
  1. Report: "Reference error: roleType 'abc-123' not found in target environment"
  2. Suggest alternative roleTypes with similar names
  3. EITHER abort import and require user to resolve, OR
  4. Import with unresolved reference marked as "pending-review"
- AND the import SHALL NOT silently ignore missing references

---

### Requirement: API Contract

The workflow engine SHALL provide REST endpoints for workflow CRUD, version management, and transition execution.

#### Scenario: GET /api/workflows/{caseType} returns active workflow for case type

- GIVEN a case type "Omgevingsvergunning"
- WHEN an admin calls `GET /api/workflows/omgevingsvergunning`
- THEN the response SHALL include:
  - workflowTemplate object with all steps, transitions, guards, actions
  - HTTP 200 if a workflow exists
  - HTTP 404 if no workflow has been configured
- AND the response SHALL contain the currently active version (where isActive=true)

#### Scenario: POST /api/workflows/{caseType}/versions creates new draft version

- GIVEN a request to `POST /api/workflows/omgevingsvergunning/versions`
- WITH body: { title, description, steps: [...], transitions: [...] }
- WHEN the request is authenticated and authorized (admin)
- THEN a new workflowTemplate SHALL be created with:
  - version = max(existing versions) + 1
  - isDraft = true
  - isActive = false
  - createdAt = now
- AND HTTP 201 returned with the new template object

#### Scenario: POST /api/workflows/{caseType}/versions/{version}/activate publishes version

- GIVEN a draft workflowTemplate version 3
- WHEN an admin calls `POST /api/workflows/omgevingsvergunning/versions/3/activate`
- THEN version 3 SHALL be marked as:
  - isActive = true
  - isDraft = false
  - validFrom = today
- AND all previous active versions SHALL be marked isActive = false
- AND HTTP 200 returned with the activated template

#### Scenario: POST /api/cases/{caseId}/transitions/{transitionId} executes workflow transition

- GIVEN a case with available transitions
- WHEN a case handler calls `POST /api/cases/om-2026-0042/transitions/trans-123`
- WITH optional body: { comment: "Checked and approved" }
- THEN the system SHALL:
  1. Load case's bound workflow + version
  2. Evaluate transition guards
  3. If unmet: return HTTP 409 with { error, unmetGuards: [...] }
  4. If met: perform transition, execute actions, return HTTP 200
- AND the response SHALL include updated case.status and activity log entry

---

### Requirement: No Developer Involvement

Workflow definitions are user-configurable through a visual editor. Functional administrators SHALL be able to create, edit, publish, and export workflows WITHOUT writing code or involving developers.

#### Scenario: Admin creates new workflow entirely through UI

- GIVEN an admin in the workflow editor
- WHEN they:
  1. Drag status nodes onto the canvas
  2. Connect with transition arrows
  3. Configure steps (checklist items, assignee role)
  4. Configure transition guards (drag-drop selection from UI)
  5. Configure automatic actions (email template, task config, etc)
  6. Click "Publish"
- THEN a complete, functional workflow SHALL be created and deployed
- AND no code deployment, git commit, or developer intervention is required
- AND the workflow is immediately usable for new cases
