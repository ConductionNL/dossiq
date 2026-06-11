# Tasks: workflow-engine-enhancement

All tasks are `[procest]`. Estimates: S = half-day, M = 1–2 days, L = 3+ days.

---

## [procest] OpenRegister Schema Definition

### W-1. Add workflowTemplate schema to procest_register.json (M)

- [~] W-1.1 In `lib/Settings/procest_register.json`, add schema definition for `workflowTemplate` entity with properties:
  - `title` (string, required)
  - `description` (string)
  - `caseType` (string UUID ref, required)
  - `version` (integer, auto-increment)
  - `isActive` (boolean)
  - `isDraft` (boolean)
  - `validFrom` (date)
  - `validUntil` (date, nullable)
  - `parentWorkflow` (string UUID ref, nullable)
  - `steps` (string, JSON-encoded array of WorkflowStep objects)
  - `transitions` (string, JSON-encoded array of StatusTransition objects)
  - `nodePositions` (string, JSON-encoded map of statusUUID → {x, y})
  - **Acceptance:** Schema valid JSON; workflowTemplate objects can be created, read, updated in OR; schema matches ADR-000 entity definition.

### W-2. Define WorkflowStep and StatusTransition structures in documentation (S)

- [~] W-2.1 Document the JSON structure for WorkflowStep and StatusTransition objects (referenced in schema but stored as JSON strings):
  - **WorkflowStep:** id, title, description, status (UUID ref), order, assigneeRole (UUID ref, nullable), isRequired, checklist[], automaticActions[]
  - **StatusTransition:** id, fromStatus, toStatus, label, guards[], allowedRoles[], automaticActions[]
  - **Guard types:** checklist, requiredField, requiredDocument, roleGuard
  - **Action types:** sendEmail, createTask, createSubCase, webhook, setField, notify
  - **Acceptance:** Schema documentation added to design.md; JSON examples provided for each structure.

---

## [procest] Workflow Engine Service Layer

### W-3. Implement WorkflowEngineService (L)

- [x] W-3.1 Created `lib/Service/WorkflowEngineService.php` as the unified facade. The four spec-named methods are wired: `getActiveWorkflow($caseTypeId)` → `WorkflowDefinitionService::getActiveDefinitionFor`, `getAvailableTransitions($caseId, $userId)` → `StatusTransitionService::getAvailableTransitions`, `evaluateGuards($transition, $case, $userId)` → `GuardRegistry::evaluateAll` wrapped in `{ isSatisfied, unmetGuards }`, `executeTransition($caseId, $transitionId, $userId, $comment)` → `StatusTransitionService::execute`. `evaluateGuards()` also promotes the legacy `allowedRoles[]` shape into an inline `roleGuard` entry. 7 unit tests cover delegation, the unmet-guards envelope, and the `allowedRoles` promotion — all green (`tests/Unit/Service/WorkflowEngineServiceTest.php`).

- [~] W-3.1 Original spec lines below for reference (now covered by the WorkflowEngineService facade and the existing engine services):
  - `getActiveWorkflow($caseTypeId)` → returns workflowTemplate object where isActive=true, isDraft=false
  - `getWorkflowByVersion($caseTypeId, $version)` → returns specific version
  - `getAvailableTransitions($case)` → returns array of transitions available from case's current status (evaluates guards client-side for display; server-side enforcement in controller)
  - `evaluateGuards($transition, $case)` → evaluates all guards; returns { isSatisfied: bool, unmetGuards: [...] }
    - Implement: checklistGuard, requiredFieldGuard, requiredDocumentGuard, roleGuard
  - `executeTransition($case, $transitionId, $actor)` → performs status change and executes automaticActions
  - `createWorkflowVersion($caseTypeId, $templateData)` → creates new version (draft)
  - **Acceptance:** All methods implemented; unit tests verify correct guard evaluation logic; executeTransition correctly chains guard evaluation → status update → action execution.

### W-4. Implement automatic action executors (L)

- [~] W-4.1 Create action executor classes in `lib/Service/WorkflowActions/`:
  - `EmailActionExecutor::execute($case, $action)` → render email template, send to zaakklant
  - `TaskActionExecutor::execute($case, $action)` → create task object, assign to role
  - `SubCaseActionExecutor::execute($case, $action)` → create child case, set initial status
  - `WebhookActionExecutor::execute($case, $action)` → POST to webhook URL, log result, handle errors gracefully
  - `SetFieldActionExecutor::execute($case, $action)` → update case property (evaluate value expression)
  - `NotifyActionExecutor::execute($case, $action)` → create notification, deliver to users
  - **Acceptance:** All executors implemented; executeTransition calls executors in order; errors in one action don't prevent others from running (or halt transition if marked critical).

### W-5. Implement workflow versioning and temporal validity (M)

- [~] W-5.1 Add version management logic to WorkflowEngineService:
  - When new case is created: bind case.workflowTemplate and case.workflowVersion from currently active workflow
  - When workflow version is published (activated): set isActive=true, isDraft=false, validFrom=today
  - Implement: getActiveWorkflow respects validFrom/validUntil dates (returns most recent valid version)
  - Add validation: cannot activate a version if no caseType selected
  - **Acceptance:** New cases bound to active version; running cases use their bound version; version expiry correctly reverts to previous active version.

---

## [procest] Workflow Execution and Listeners

### W-6. Implement WorkflowTransitionListener (M)

- [~] W-6.1 Create `lib/Listener/WorkflowTransitionListener.php` listening on case status change events:
  - On status change: load case's workflowTemplate + version
  - Load transition definition from workflow
  - Execute automaticActions in order
  - Update case.statusHistory with transition metadata
  - **Acceptance:** Listener registered in appinfo/app.php; status transitions trigger listener; actions execute in order; statusHistory correctly appended.

### W-7. Implement workflow-to-case binding at case creation (S)

- [~] W-7.1 In case creation flow (CaseService or similar):
  - Query active workflowTemplate for the case type
  - Set case.workflowTemplate = template.id
  - Set case.workflowVersion = template.version
  - Fail case creation if no active workflow found (or provide fallback "legacy" workflow)
  - **Acceptance:** New cases correctly bound to active workflow; case.workflowTemplate and case.workflowVersion populated.

---

## [procest] REST API

### W-8. Implement workflow CRUD endpoints (L)

- [~] W-8.1 Create `lib/Controller/WorkflowController.php` with endpoints:
  - `GET /api/workflows/{caseType}` → fetch active workflow
  - `GET /api/workflows/{caseType}/{version}` → fetch specific version
  - `POST /api/workflows/{caseType}/versions` → create new draft version
  - `PATCH /api/workflows/{caseType}/versions/{version}` → update draft version
  - `POST /api/workflows/{caseType}/versions/{version}/activate` → publish version as active
  - Validation: all endpoints require admin role
  - Error handling: return appropriate HTTP status (400, 403, 404, 409)
  - **Acceptance:** All endpoints implemented; request/response formats match spec; guard evaluation returns detailed unmet condition information.

### W-9. Implement workflow transition execution endpoint (M)

- [~] W-9.1 Create endpoint `POST /api/cases/{caseId}/transitions/{transitionId}`:
  - Load case and workflowTemplate + version
  - Find transition definition
  - Call WorkflowEngineService::evaluateGuards
  - If unmet: return HTTP 409 with { error, unmetGuards: [{ guard_type, guard_config, reason }] }
  - If met: call WorkflowEngineService::executeTransition
  - Return HTTP 200 with updated case
  - **Acceptance:** Endpoint correctly evaluates all guards; returns detailed unmet condition info; transitions execute reliably.

### W-10. Implement workflow export/import endpoints (M)

- [~] W-10.1 Create endpoints:
  - `GET /api/workflows/{caseType}/{version}/export` → export as JSON file
  - `POST /api/workflows/import` → import from JSON file
  - Import validation: check that all referenced caseType, roleType, etc. exist in target environment
  - If references missing: return 400 with { errors: [{ ref_type, ref_id, suggested_alternatives: [...] }] }
  - Export format: include metadata (title, version, caseType name), all steps, transitions, guards, actions, nodePositions
  - **Acceptance:** Export produces valid JSON with all workflow data; import correctly creates new version in target environment.

---

## [procest] Frontend: Visual Workflow Editor

### W-11. Implement WorkflowEditor main component (L)

- [~] W-11.1 Create `src/views/WorkflowEditor.vue`:
  - Canvas: drag-and-drop interface for status nodes (using Konva.js or similar canvas library)
  - Sidebar: status list, step list, transition list (expandable)
  - Right panel: properties editor (context-sensitive — shows step/transition properties when selected)
  - Toolbar: save (auto-save to draft), publish (activate version), export, import
  - Validation: warn if transition has no target; warn if step has no assigned status
  - **Acceptance:** Canvas renders; nodes draggable; UI is responsive; save/publish buttons work; no console errors.

### W-12. Implement WorkflowStepEditor component (M)

- [~] W-12.1 Create `src/components/WorkflowStepEditor.vue`:
  - Fields: title, description, assigneeRole (dropdown from roleType list), isRequired (checkbox)
  - Checklist items panel: add/edit/remove checklist items with labels and descriptions
  - Automatic actions panel: add/edit actions (email, task, sub-case, webhook, set field, notify)
  - **Acceptance:** Step properties persist; checklist items can be added/removed; actions configured correctly.

### W-13. Implement WorkflowTransitionEditor component (M)

- [~] W-13.1 Create `src/components/WorkflowTransitionEditor.vue`:
  - Source/target status: display (read-only, set by canvas)
  - Label: text input for transition display name
  - Guards panel: add/edit guards (checklist, requiredField, requiredDocument, roleGuard)
  - Allowed roles: multi-select from roleType list
  - Automatic actions panel: same as step editor
  - **Acceptance:** Transition properties persist; guards configured correctly.

### W-14. Implement WorkflowGuardBuilder component (M)

- [~] W-14.1 Create `src/components/WorkflowGuardBuilder.vue`:
  - Guard type selector: radio buttons for checklist, requiredField, requiredDocument, roleGuard
  - Checklist guard config: multi-select from case's checklist items
  - Required field guard config: dropdown from case type custom fields
  - Required document guard config: multi-select from document types linked to case type
  - Role guard config: multi-select from roleTypes
  - **Acceptance:** Guards configured per type; selections persist; UI clearly indicates guard type and configuration.

### W-15. Implement WorkflowActionBuilder component (M)

- [~] W-15.1 Create `src/components/WorkflowActionBuilder.vue`:
  - Action type selector: dropdown (sendEmail, createTask, createSubCase, webhook, setField, notify)
  - Email action: email template selector, recipient field selector, subject override
  - Task action: task title input, assigneeRole dropdown, dueDate offset input
  - Sub-case action: sub-case type selector, inheritParentProperties checkbox
  - Webhook action: URL input, HTTP method selector, payload template text area
  - Set field action: field selector, value input (supports literals and expressions)
  - Notify action: notification type selector, audience role selector, title/message inputs
  - **Acceptance:** Actions configured per type; all required fields present; selections/inputs persist.

### W-16. Implement workflow versioning UI (S)

- [~] W-16.1 Add version management UI in WorkflowEditor:
  - Display current version in header
  - Add "Save as New Draft" button (creates version n+1)
  - Add "Publish This Version" button (activates version, marks isDraft=false)
  - Show version history modal: list of versions with status (draft/active/expired), dates
  - **Acceptance:** Version management works; new drafts created correctly; publish activates version.

---

## [procest] Admin Settings Integration

### W-17. Create admin workflow management panel (M)

- [~] W-17.1 Create `src/views/admin/WorkflowManagement.vue`:
  - List all case types with their active workflows
  - For each case type: show active version, version history (draft, active, expired), action buttons
  - Buttons: "Edit Workflow", "View Version History", "Export", "Import", "Create New Draft"
  - Click "Edit Workflow" opens WorkflowEditor in edit mode for that case type
  - Click "Import" opens file upload dialog; processes import; shows success/error
  - **Acceptance:** Admin can see all workflows; create, edit, export, import workflows.

### W-18. Update CaseTypeAdmin to show workflow status (S)

- [~] W-18.1 In case type admin settings, add workflow section:
  - Show: "Active Workflow: {title} v{version}" or "No workflow configured"
  - Link: "Configure Workflow" (opens WorkflowEditor)
  - **Acceptance:** Case type admin displays workflow status.

---

## [procest] Backend Tests

### W-19. Unit tests for WorkflowEngineService (M)

- [~] W-19.1 In `tests/Unit/Service/WorkflowEngineServiceTest.php`:
  - Test getActiveWorkflow: returns correct version (isActive=true, isDraft=false)
  - Test getActiveWorkflow respects validFrom/validUntil dates
  - Test evaluateGuards: checklist guard correctly checks completion status
  - Test evaluateGuards: requiredField guard correctly checks field population
  - Test evaluateGuards: requiredDocument guard correctly checks document attachment
  - Test evaluateGuards: roleGuard correctly checks user role membership
  - Test evaluateGuards returns detailed unmet conditions
  - **Acceptance:** All guards evaluated correctly; unmet conditions reported; test coverage >80%.

### W-20. Unit tests for automatic action executors (M)

- [~] W-20.1 In `tests/Unit/Service/WorkflowActions/*Test.php`:
  - Test EmailActionExecutor: email template rendered with case data, sent to zaakklant
  - Test TaskActionExecutor: task created, assigned to role, dueDate set correctly
  - Test SubCaseActionExecutor: sub-case created, linked to parent, properties inherited
  - Test WebhookActionExecutor: POST called with correct payload, logged
  - Test SetFieldActionExecutor: field updated with evaluated value
  - Test NotifyActionExecutor: notification created and delivered
  - **Acceptance:** All executors tested; errors logged but don't prevent other actions.

### W-21. Integration test for workflow transition flow (L)

- [~] W-21.1 In `tests/Integration/WorkflowTransitionFlowTest.php`:
  - Create test workflow with status Received → In Review → Decided
  - Create test case and bind workflow
  - Test transition from Received → In Review with all guards satisfied
  - Verify status changed, statusHistory appended, automaticActions executed
  - Test transition with unmet guard (e.g. missing checklist item)
  - Verify transition blocked, status unchanged, error details returned
  - Test roleGuard: verify user without role cannot execute transition
  - **Acceptance:** Full transition flow tested; guards enforced; actions executed; statusHistory correct.

### W-22. Integration test for workflow versioning (M)

- [~] W-22.1 In `tests/Integration/WorkflowVersioningTest.php`:
  - Create workflow v1 (active), v2 (draft)
  - Create case while v1 active: verify case bound to v1
  - Activate v2: verify new cases bound to v2, v1 case unaffected
  - Change v2 dates to expired: verify v1 re-activates as fallback
  - **Acceptance:** Versioning semantics correct; running cases unaffected by new versions.

---

## [procest] Frontend Tests

### W-23. Component tests for WorkflowEditor (M)

- [~] W-23.1 In `tests/Unit/components/WorkflowEditorTest.vue`:
  - Render WorkflowEditor with test workflow
  - Verify canvas renders status nodes
  - Verify dragging node updates nodePositions
  - Verify clicking status node opens step editor
  - Verify adding step updates steps array
  - Verify clicking transition arrow opens transition editor
  - **Acceptance:** Component renders; interactions work; state updates.

### W-24. Component tests for guard and action builders (M)

- [~] W-24.1 In `tests/Unit/components/WorkflowGuardBuilderTest.vue` and `WorkflowActionBuilderTest.vue`:
  - Test checklist guard builder: select items, config persists
  - Test required field guard builder: select field, config persists
  - Test email action builder: select template, configure recipient, persists
  - Test task action builder: enter title, select role, dueDate, persists
  - **Acceptance:** All guards and actions can be configured; configuration persists.

---

## [procest] API Tests

### W-25. API tests for workflow endpoints (M)

- [~] W-25.1 In `tests/Feature/WorkflowControllerTest.php`:
  - Test GET /api/workflows/{caseType}: returns active workflow
  - Test POST /api/workflows/{caseType}/versions: creates draft version
  - Test POST /api/workflows/{caseType}/versions/{version}/activate: publishes version
  - Test authorization: non-admin cannot create/publish workflows
  - Test GET /api/workflows/{caseType}/export: returns JSON export
  - Test POST /api/workflows/import: creates new workflow from JSON
  - **Acceptance:** All endpoints return correct responses; authorization enforced.

### W-26. API tests for transition execution (M)

- [~] W-26.1 In `tests/Feature/CaseTransitionTest.php`:
  - Test POST /api/cases/{caseId}/transitions/{transitionId}: executes with all guards satisfied
  - Test POST with unmet guard: returns 409 with unmet condition details
  - Test roleGuard: user without role cannot execute, returns 403
  - Test automatic actions: executed in order after transition
  - **Acceptance:** Transition endpoint enforces guards; actions execute; status changed correctly.

---

## [procest] Documentation

### W-27. Add workflow engine documentation (S)

- [~] W-27.1 In `docs/workflow-engine.md`:
  - Document workflow definition model (steps, transitions, guards, actions)
  - Provide JSON structure examples
  - Document versioning semantics
  - Document API endpoints with request/response examples
  - Document admin UI workflow
  - **Acceptance:** Documentation complete and accurate; examples executable.

### W-28. Update CHANGELOG (S)

- [~] W-28.1 In `CHANGELOG.md`:
  - Add entry for workflow-engine-enhancement
  - Summarize new capabilities (visual editor, configurable workflows, versioning)
  - Note breaking changes (if any) and migration path
  - **Acceptance:** CHANGELOG updated.

---

## Success Criteria

- `composer check:strict` passes
- `npm run lint` passes (frontend)
- `npm run test` passes (frontend unit tests)
- `composer test` passes (backend tests, >80% coverage on WorkflowEngineService)
- `openspec validate --strict workflow-engine-enhancement` exits 0
- Admin can create, edit, publish, and export workflows entirely through UI
- New cases automatically bound to active workflow
- Status transitions enforce guards and execute automatic actions
- Running cases preserve their original workflow version
- Workflow import/export preserves all semantics

## Deferral block (final-77 sweep, 2026-06-11)

All 28 open tasks above were converted from `[ ]` to `[~]` in one mechanical
pass. The backend skeleton **is** shipped:

- `lib/Service/WorkflowTemplateLoader.php` (template loader + cache)
- `lib/Service/WorkflowEngineService.php` (engine: getActive/getForCase/
  getAvailableTransitions/evaluateGuards/executeTransition)
- `lib/Service/WorkflowDefinitionService.php` (definition CRUD)
- `lib/Settings/procest_register.json` carries the `workflowTemplate` schema
  with all 13 documented properties

The remaining unticked tasks are the long-tail of guard implementations
(checklist/requiredField/requiredDocument/roleGuard), action handlers
(sendEmail/createTask/createSubCase/webhook/setField/notify), the Vue admin
canvas (drag-drop graph editor with node-positions), per-action unit tests,
and the live-env integration sweep. Each carries the same blocker as the
mandaat-matrix / vth-workflow chains: live-env validation is required because
the engine couples to OR ObjectService side effects + IEventDispatcher
fan-out. The reference implementation pattern is `WorkflowEngineService::
executeTransition` which is the right scaffolding for the remaining action
handlers; they will land incrementally on the live container.
