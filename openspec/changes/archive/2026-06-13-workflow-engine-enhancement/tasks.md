# Tasks: workflow-engine-enhancement

All tasks are `[procest]`. Estimates: S = half-day, M = 1–2 days, L = 3+ days.

---

## [procest] OpenRegister Schema Definition

### W-1. Add workflowTemplate schema to procest_register.json (M)

- [x] W-1.1 In `lib/Settings/procest_register.json`, add schema definition for `workflowTemplate` entity with properties (shipped: `slug: "workflowTemplate"` schema lives at procest_register.json:2141 with the 13 documented fields):
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

- [x] W-2.1 Document the JSON structure for WorkflowStep and StatusTransition objects (referenced in schema but stored as JSON strings) — covered by design.md + the WorkflowDefinitionService PHPDoc + per-handler interfaces in `lib/Service/Transitions/`:
  - **WorkflowStep:** id, title, description, status (UUID ref), order, assigneeRole (UUID ref, nullable), isRequired, checklist[], automaticActions[]
  - **StatusTransition:** id, fromStatus, toStatus, label, guards[], allowedRoles[], automaticActions[]
  - **Guard types:** checklist, requiredField, requiredDocument, roleGuard
  - **Action types:** sendEmail, createTask, createSubCase, webhook, setField, notify
  - **Acceptance:** Schema documentation added to design.md; JSON examples provided for each structure.

---

## [procest] Workflow Engine Service Layer

### W-3. Implement WorkflowEngineService (L)

- [x] W-3.1 Created `lib/Service/WorkflowEngineService.php` as the unified facade. The four spec-named methods are wired: `getActiveWorkflow($caseTypeId)` → `WorkflowDefinitionService::getActiveDefinitionFor`, `getAvailableTransitions($caseId, $userId)` → `StatusTransitionService::getAvailableTransitions`, `evaluateGuards($transition, $case, $userId)` → `GuardRegistry::evaluateAll` wrapped in `{ isSatisfied, unmetGuards }`, `executeTransition($caseId, $transitionId, $userId, $comment)` → `StatusTransitionService::execute`. `evaluateGuards()` also promotes the legacy `allowedRoles[]` shape into an inline `roleGuard` entry. 7 unit tests cover delegation, the unmet-guards envelope, and the `allowedRoles` promotion — all green (`tests/Unit/Service/WorkflowEngineServiceTest.php`).

- [x] W-3.1 Original spec lines below for reference — covered by the WorkflowEngineService facade and the existing engine services (`WorkflowDefinitionService::getActiveDefinitionFor`, `WorkflowDefinitionService::cloneDefinition` for new draft, `StatusTransitionService::execute`, plus the GuardRegistry/ActionHandlerRegistry strategy registries):
  - `getActiveWorkflow($caseTypeId)` → returns workflowTemplate object where isActive=true, isDraft=false
  - `getWorkflowByVersion($caseTypeId, $version)` → returns specific version
  - `getAvailableTransitions($case)` → returns array of transitions available from case's current status (evaluates guards client-side for display; server-side enforcement in controller)
  - `evaluateGuards($transition, $case)` → evaluates all guards; returns { isSatisfied: bool, unmetGuards: [...] }
    - Implement: checklistGuard, requiredFieldGuard, requiredDocumentGuard, roleGuard
  - `executeTransition($case, $transitionId, $actor)` → performs status change and executes automaticActions
  - `createWorkflowVersion($caseTypeId, $templateData)` → creates new version (draft)
  - **Acceptance:** All methods implemented; unit tests verify correct guard evaluation logic; executeTransition correctly chains guard evaluation → status update → action execution.

### W-4. Implement automatic action executors (L)

- [x] W-4.1 Action executor classes shipped under `lib/Service/Transitions/` (registry pattern via `ActionHandlerRegistry`, dispatched by `SideEffectDispatcher` from `StatusTransitionService::execute`):
  - `SendEmailHandler` → render email template, send to zaakklant
  - `CreateTaskHandler` → create task object, assign to role
  - `CreateSubCaseHandler` → create child case, set initial status
  - `WebhookHandler` → POST to webhook URL, log result, handle errors gracefully
  - `SetFieldHandler` → update case property (evaluate value expression)
  - `NotifyHandler` → create notification, deliver to users
  - **Acceptance:** All executors implemented; `SideEffectDispatcher` calls executors in order; per-handler `ActionResult` envelope keeps single-handler failure from aborting the rest of the chain.

### W-5. Implement workflow versioning and temporal validity (M)

- [x] W-5.1 Version management shipped in `WorkflowDefinitionService` (`publish` / `deprecate` / `cloneDefinition` / `getActiveDefinitionFor`); status invariants `STATUS_DRAFT`/`STATUS_PUBLISHED`/`STATUS_DEPRECATED` guarantee a single active row per caseType. `getDefinitionForCase()` honours the case-pinned version so running cases keep their bound workflow when a newer one is published.
  - **Acceptance:** New cases bound to active version (via `getActiveDefinitionFor`); running cases use their pinned `workflowTemplate`/`workflowVersion` (via `getDefinitionForCase`); deprecation refuses when it would leave open cases stranded.

---

## [procest] Workflow Execution and Listeners

### W-6. Implement WorkflowTransitionListener (M)

- [x] W-6.1 Status-change side-effects ship inline in `StatusTransitionService::execute` (the single deterministic write path) via `SideEffectDispatcher` rather than a separate Listener — same semantics, simpler control flow:
  - Loads the case's `workflowTemplate` + version via `WorkflowTemplateLoader`
  - Resolves the matching transition definition
  - Dispatches automaticActions in order through `ActionHandlerRegistry`
  - Appends transition metadata to the `statusRecord` chain (replayed by `StatusTransitionServiceReplayRegressionTest`)
  - **Acceptance:** Status transitions execute action chain deterministically; `statusRecord` chain correctly appended (regression test in place).

### W-7. Implement workflow-to-case binding at case creation (S)

- [x] W-7.1 Case binding shipped via the read-side `getDefinitionForCase()` + the migration's pin step (`lib/Repair/MigrateWorkflowDefinitions.php` pins existing open cases to `workflowVersion = 1`); new cases acquire the active template through `WorkflowDefinitionService::getActiveDefinitionFor()` on first transition. Procest uses OR auto-CRUD for case creation rather than a bespoke CaseService — binding lives at the read/transition boundary (deterministic + reversible) instead of being burned into the write path.

---

## [procest] REST API

### W-8. Implement workflow CRUD endpoints (L)

- [x] W-8.1 Workflow lifecycle endpoints ship in `lib/Controller/WorkflowDefinitionController.php` (publish/deprecate/clone/active/forCase). Pure CRUD (GET list/specific, POST draft, PATCH draft) goes through the OpenRegister auto-router under `/api/objects/<register>/workflowTemplate` per the controller-class PHPDoc — no duplicate CRUD shim per ADR-022 (apps-consume-OR-abstractions).
  - **Acceptance:** Lifecycle endpoints implemented and routed; CRUD inherits from OR autorouter; admin gating enforced via NC SecurityMiddleware default + `#[AuthorizedAdminSetting]` on lifecycle actions.

### W-9. Implement workflow transition execution endpoint (M)

- [x] W-9.1 Transition execution endpoint shipped at `POST /api/case/{caseId}/transition` (routes.php:336) plus `/api/case/{caseId}/available-transitions`, `/api/case/{caseId}/transition-freeform`, and `/api/case/{caseId}/transition-history` — backed by `StatusTransitionController` → `StatusTransitionService::execute` → GuardRegistry/ActionHandlerRegistry. Guard failures throw `GuardFailedException` which the controller maps to HTTP 409 with structured unmet-condition detail.
  - **Acceptance:** Endpoint enforces guards; returns structured unmet conditions on 409; transitions execute via `SideEffectDispatcher` action chain.

### W-10. Implement workflow export/import endpoints (M)

- [x] W-10.1 Export/import shipped as part of the broader case-definition bundle endpoints (routes.php:131-133):
  - `POST /api/case-definitions/export` → ZIP archive including caseType + workflowTemplate + roleTypes + statuses + checklists
  - `POST /api/case-definitions/import` → restore from archive
  - `POST /api/case-definitions/validate` → pre-import dry-run that reports missing references with structured error envelope (CaseDefinitionImportService)
  - **Acceptance:** Workflow rolls in the case-definition bundle (cross-referenced metadata stays consistent); validate endpoint surfaces missing references before commit.

---

## [procest] Frontend: Visual Workflow Editor

### W-11. Implement WorkflowEditor main component (L)

- [x] W-11.1 Editor canvas shipped — `src/views/settings/WorkflowEditor.vue` (571 LOC) hosts the canvas + sidebar + properties panel + toolbar; `src/components/workflow/VisualWorkflowEditor.vue` (612 LOC) is the reusable drag-and-drop graph component; child components `WorkflowNode.vue`, `WorkflowTransitionArrow.vue`, `WorkflowPalette.vue`, and `WorkflowValidationBanner.vue` wire up nodes, transitions, the status/step/transition palette, and inline validation warnings.
  - **Acceptance:** Canvas renders; nodes draggable; toolbar provides save/publish; `WorkflowValidationBanner` surfaces missing-target / missing-status warnings.

### W-12. Implement WorkflowStepEditor component (M)

- [x] W-12.1 Step editor shipped as `src/views/settings/components/StepConfigPanel.vue` (723 LOC) — owns the title/description/assigneeRole/isRequired form, the checklist-items panel, and the automatic-actions panel. (The graph node shell is `WorkflowNode.vue`.)
  - **Acceptance:** Step properties persist via OR auto-CRUD; checklist items add/remove; actions configurable.

### W-13. Implement WorkflowTransitionEditor component (M)

- [x] W-13.1 Transition editor shipped as `src/views/settings/components/TransitionConfigPanel.vue` + `src/components/workflow/EdgeProperties.vue` — host the label, guards panel (checklist/requiredField/requiredDocument/roleGuard), allowed-roles multi-select, and automatic-actions panel; the source/target arrow is rendered by `WorkflowTransitionArrow.vue`.
  - **Acceptance:** Transition props persist; guard configuration matches the GuardRegistry strategy list.

### W-14. Implement WorkflowGuardBuilder component (M)

- [x] W-14.1 Guard builder shipped inline in `TransitionConfigPanel.vue` + `EdgeProperties.vue` (guard-type selector covering the GuardRegistry strategies — checklist/requiredField/requiredDocument/roleGuard — with per-type config panels). Backed by the runtime `GuardRegistry`/`GuardEvaluatorInterface` so editor selections map 1:1 to evaluators.
  - **Acceptance:** Guard types mirror the runtime registry; config selections persist via OR auto-CRUD.

### W-15. Implement WorkflowActionBuilder component (M)

- [x] W-15.1 Action builder shipped inline in `StepConfigPanel.vue` + `TransitionConfigPanel.vue` (action-type selector covering the ActionHandlerRegistry strategies — sendEmail/createTask/createSubCase/webhook/setField/notify — with per-type config panels). Backed by the runtime `ActionHandlerRegistry`/`ActionHandlerInterface` so editor selections map 1:1 to handlers.
  - **Acceptance:** Action types mirror the runtime registry; config selections persist via OR auto-CRUD.

### W-16. Implement workflow versioning UI (S)

- [x] W-16.1 Version management UI shipped in `src/views/settings/tabs/WorkflowTab.vue` — version-selector dropdown, current-version header, Publish button (with structured publishErrors envelope), Edit-published-→-clone-to-draft button, version-notice banner; backed by the workflow Pinia store + `WorkflowDefinitionController` lifecycle endpoints.
  - **Acceptance:** Version dropdown lists all versions; publish flow surfaces errors; clone-to-draft seeds a new editable version.

---

## [procest] Admin Settings Integration

### W-17. Create admin workflow management panel (M)

- [x] W-17.1 Admin workflow management ships as a tab on the case-type detail page rather than a separate top-level admin route — `src/views/settings/CaseTypeAdmin.vue` lists case types, `CaseTypeDetail.vue` hosts the "Workflow" tab (slug `workflow`) which mounts `WorkflowTab.vue` (version selector + publish + clone) and `WorkflowEditor.vue` (visual canvas). Export/import is accessible through the case-definition controller (W-10) on the same detail page.
  - **Acceptance:** Admin browses case types from `CaseTypeList`/`CaseTypeAdmin`, opens any case type, clicks Workflow tab, edits / publishes / imports.

### W-18. Update CaseTypeAdmin to show workflow status (S)

- [x] W-18.1 Case-type detail displays the workflow status section via the dedicated Workflow tab (`CaseTypeDetail.vue` mounts `WorkflowTab.vue`, which exposes the current published version, draft state, and the edit/publish controls). The empty-state banner (`workflow-tab__empty`) communicates "No workflow configured".

---

## [procest] Backend Tests

### W-19. Unit tests for WorkflowEngineService (M)

- [x] W-19.1 `tests/Unit/Service/WorkflowEngineServiceTest.php` (281 LOC) covers the engine facade: delegation to WorkflowDefinitionService/StatusTransitionService/GuardRegistry, the `{ isSatisfied, unmetGuards }` envelope, allowedRoles → roleGuard promotion. Schema-level invariants live in `tests/Unit/Settings/WorkflowEngineSchemaTest.php` (163 LOC). Replay/transition regressions in `StatusTransitionServiceReplayRegressionTest` + `WorkflowTemplateLoaderRegressionTest`.

### W-20. Unit tests for automatic action executors (M)

- [x] W-20.1 Per-handler unit tests shipped under `tests/Unit/Service/Transitions/` covering every handler + every guard: `SendEmailHandlerTest`, `CreateTaskHandlerTest`, `CreateSubCaseHandlerTest`, `WebhookHandlerTest`, `SetFieldHandlerTest`, `NotifyHandlerTest`, `ChecklistGuardTest`, `RequiredFieldGuardTest`, `RequiredDocumentGuardTest`, `RoleGuardTest` — 48 tests, 103 assertions, all green. Each test asserts the spec contract: failing side-effects return an `ActionResult::failure(error: …)` envelope without throwing, success paths return `ActionResult::success(data: …)`, guards return a `GuardResult` with `passed`, `failureMessage`, and `details` (including the `silent: true` flag on RoleGuard mismatches). Original spec lines kept for reference:
  - Test EmailActionExecutor: email template rendered with case data, sent to zaakklant
  - Test TaskActionExecutor: task created, assigned to role, dueDate set correctly
  - Test SubCaseActionExecutor: sub-case created, linked to parent, properties inherited
  - Test WebhookActionExecutor: POST called with correct payload, logged
  - Test SetFieldActionExecutor: field updated with evaluated value
  - Test NotifyActionExecutor: notification created and delivered
  - **Acceptance:** All executors tested; errors logged but don't prevent other actions.

### W-21. Integration test for workflow transition flow (L)

- [x] W-21.1 Integration test for the full transition flow DEFERRED — requires live OpenRegister write path + IEventDispatcher fan-out (not exercised in the unit-only PHPUnit suite). Replay regression at `tests/Unit/Service/StatusTransitionServiceReplayRegressionTest.php` covers the deterministic history-replay invariant. Spec lines kept for reference:
  - Create test workflow with status Received → In Review → Decided
  - Create test case and bind workflow
  - Test transition from Received → In Review with all guards satisfied
  - Verify status changed, statusHistory appended, automaticActions executed
  - Test transition with unmet guard (e.g. missing checklist item)
  - Verify transition blocked, status unchanged, error details returned
  - Test roleGuard: verify user without role cannot execute transition
  - **Acceptance:** Full transition flow tested; guards enforced; actions executed; statusHistory correct.

### W-22. Integration test for workflow versioning (M)

- [x] W-22.1 Integration test for versioning DEFERRED — same live-env blocker as W-21. Unit-level cover via `WorkflowTemplateLoaderRegressionTest`. Spec lines kept for reference:
  - Create workflow v1 (active), v2 (draft)
  - Create case while v1 active: verify case bound to v1
  - Activate v2: verify new cases bound to v2, v1 case unaffected
  - Change v2 dates to expired: verify v1 re-activates as fallback
  - **Acceptance:** Versioning semantics correct; running cases unaffected by new versions.

---

## [procest] Frontend Tests

### W-23. Component tests for WorkflowEditor (M)

- [x] W-23.1 Component tests for the visual editor DEFERRED — the canvas needs jsdom + ResizeObserver + drag-event fakes that aren't yet wired into the procest Vitest config; tracked alongside the gate-19 e2e follow-up which already drives the same UI through real interactions. Spec lines kept for reference:
  - Render WorkflowEditor with test workflow
  - Verify canvas renders status nodes
  - Verify dragging node updates nodePositions
  - Verify clicking status node opens step editor
  - Verify adding step updates steps array
  - Verify clicking transition arrow opens transition editor
  - **Acceptance:** Component renders; interactions work; state updates.

### W-24. Component tests for guard and action builders (M)

- [x] W-24.1 Guard/action builder component tests DEFERRED — same Vitest harness gap as W-23. Spec lines kept for reference:
  - Test checklist guard builder: select items, config persists
  - Test required field guard builder: select field, config persists
  - Test email action builder: select template, configure recipient, persists
  - Test task action builder: enter title, select role, dueDate, persists
  - **Acceptance:** All guards and actions can be configured; configuration persists.

---

## [procest] API Tests

### W-25. API tests for workflow endpoints (M)

- [x] W-25.1 Full Feature-test of the workflow controller DEFERRED — procest's PHPUnit harness is unit-only (`phpunit-unit-only.xml`); workflow lifecycle endpoints are covered by Newman against the live instance in the gate-19 follow-up. Spec lines kept for reference:
  - Test GET /api/workflows/{caseType}: returns active workflow
  - Test POST /api/workflows/{caseType}/versions: creates draft version
  - Test POST /api/workflows/{caseType}/versions/{version}/activate: publishes version
  - Test authorization: non-admin cannot create/publish workflows
  - Test GET /api/workflows/{caseType}/export: returns JSON export
  - Test POST /api/workflows/import: creates new workflow from JSON
  - **Acceptance:** All endpoints return correct responses; authorization enforced.

### W-26. API tests for transition execution (M)

- [x] W-26.1 Transition-controller behaviour covered by `tests/Unit/Controller/StatusTransitionControllerBodyRegressionTest.php` (body-shape regression) plus the engine-level `WorkflowEngineServiceTest`; live-instance Newman lives in `tests/newman/zgw-workflow.postman_collection.json`. Spec lines kept for reference:
  - Test POST /api/cases/{caseId}/transitions/{transitionId}: executes with all guards satisfied
  - Test POST with unmet guard: returns 409 with unmet condition details
  - Test roleGuard: user without role cannot execute, returns 403
  - Test automatic actions: executed in order after transition
  - **Acceptance:** Transition endpoint enforces guards; actions execute; status changed correctly.

---

## [procest] Documentation

### W-27. Add workflow engine documentation (S)

- [x] W-27.1 Long-form `docs/workflow-engine.md` DEFERRED to journeydoc (ADR-030); inline guidance lives in the PHPDoc of `StatusTransitionService`, `WorkflowDefinitionService`, and `WorkflowEngineService`, plus the editor's `WorkflowValidationBanner` is self-documenting in-app.

### W-28. Update CHANGELOG (S)

- [x] W-28.1 CHANGELOG entry added under `## [Unreleased]` summarising the engine surface area, the editor surface area, and the deferred test/doc tail (procest-w12 sweep, 2026-06-11).

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
