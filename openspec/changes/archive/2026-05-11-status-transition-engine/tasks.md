# Tasks: status-transition-engine

## Deduplication Check

- [ ] **D01**: Search `lib/Service/` and `lib/Controller/` for any existing transition engine / status orchestration. Inventory the status-mutation call-sites in `ZgwZrcRulesService::rulesStatussenCreate`, `ZrcController::handleEindstatusEffect`, and any direct `case.status` writes through `ObjectService`. Verify `WorkflowTemplateLoader` does not already exist (workflow-definition-model spec may have created a stub). Confirm `statusRecord` schema slug and whether config key `status_record_schema` is registered in `SettingsService`. Document findings here (expected: `workflowTemplate` schema exists post `workflow-definition-model` apply; `statusRecord` schema exists; no transition engine exists yet; multiple call-sites mutate `case.status` directly).

---

## Schema & Configuration

- [ ] **T01**: Extend `statusRecord` schema in `lib/Settings/procest_register.json`. Add optional fields: `transitionLabel` (string), `fromStatus` (string, format uuid), `evaluatedGuards` (array — each item `{type: string, passed: boolean, details: object}`), `dispatchedActions` (array — each item `{type: string, ok: boolean, error: string}`), `noWorkflowTemplate` (boolean, default false). Required fields remain `case` and `statusType`. Bump schema `version` to `1.1.0`.

- [ ] **T02**: Add config keys to `lib/Service/SettingsService.php`: `status_record_schema` (slug of `statusRecord` schema), `workflow_template_schema` (slug of `workflowTemplate` schema, may already exist from workflow-definition-model). Load both in `initializeStores()`.

---

## Backend: Engine Core

- [ ] **T03**: Create `lib/Service/WorkflowTemplateLoader.php`. Methods:
  - `getActiveTemplate(string $caseTypeId): ?array` — calls `ObjectService::findObjects($register, $workflowTemplateSchema, ['caseType' => $caseTypeId, 'isActive' => true])`; returns the single active template (or null) with `transitions` and `steps` already JSON-decoded.
  - `getTransitionById(string $caseTypeId, string $transitionId): ?array` — convenience accessor.
  - Per-request in-memory cache keyed by `caseTypeId`. NEVER call frontend-cached templates.

- [ ] **T04**: Create `lib/Service/Transitions/GuardEvaluatorInterface.php` with single method `evaluate(array $guardConfig, array $case, string $userId): GuardResult` where `GuardResult` is a small value class (`passed: bool`, `failureMessage: ?string`, `details: array`).

- [ ] **T05**: Implement four guard evaluators in `lib/Service/Transitions/`:
  - `ChecklistGuard.php` — loads referenced task via `ObjectService::findObject`, checks all `requiredItems` are `checked: true`; failure message lists missing item labels.
  - `RequiredFieldGuard.php` — checks `case[$guardConfig['field']]` is not null / empty string / empty array; failure message names the field.
  - `RequiredDocumentGuard.php` — iterates linked documents (via `case.relations` or `files`), checks at least one has matching `documentType`; failure message names the missing type.
  - `RoleGuard.php` — checks the current user (via `IUserSession`) has at least one role from `allowedRoles[]` on this case via the existing role mapping; failure message is silent visibility hide (`passed: false, details: ['silent' => true]`).
  All guards MUST derive user identity from `IUserSession`, NEVER from request body.

- [ ] **T06**: Create `lib/Service/Transitions/GuardRegistry.php`. Constructor takes an array of `GuardEvaluatorInterface` implementations keyed by guard type. Method `evaluateAll(array $guards, array $case, string $userId): array` returns `[['type', 'passed', 'failureMessage', 'details'], ...]`. Register all four built-in guards via DI (`Application::register`).

- [ ] **T07**: Create `lib/Service/Transitions/ActionHandlerInterface.php` with single method `handle(array $actionConfig, array $case, array $transitionContext): ActionResult` where `ActionResult` carries `ok: bool, error: ?string, data: array`. `$transitionContext` includes `fromStatus`, `toStatus`, `transitionLabel`, `userId`, `statusRecordUuid`.

- [ ] **T08**: Implement built-in action handlers in `lib/Service/Transitions/`:
  - `SendEmailHandler.php` — delegates to `NotificatieService::sendEmail`.
  - `CreateTaskHandler.php` — delegates to existing tasks service to create a task linked to the case.
  - `CreateSubCaseHandler.php` — delegates to `ZgwService` to create a deelzaak linked via `hoofdzaak`.
  - `WebhookHandler.php` — outbound HTTP POST via `IClientService` with case payload + transition context; 5s timeout; non-2xx → `ok: false`.
  - `SetFieldHandler.php` — writes `actionConfig.field` = `actionConfig.value` on the case via `ObjectService::saveObject` (3-arg API).
  - `NotifyHandler.php` — delegates to `NotificatieService` for in-app notification.
  All handlers catch `\Throwable`, log via `$this->logger->error()` with full context, return `ActionResult { ok: false, error: <static-message> }` — NEVER bubble exceptions, NEVER include `$e->getMessage()` in `ActionResult.error`.

- [ ] **T09**: Create `lib/Service/Transitions/SideEffectDispatcher.php`. Method `dispatch(array $actions, array $case, array $transitionContext): array` iterates actions **in declaration order**, invokes the handler registered for `actionConfig.type`, collects results into `[{type, ok, error?}, ...]`. Failed handlers SHALL NOT abort the loop. Unregistered action types log a warning and return `{type, ok: false, error: 'unknown_action_type'}`.

- [ ] **T10**: Create `lib/Service/StatusTransitionService.php`. Methods:
  - `getAvailableTransitions(string $caseId, string $userId): array` — loads case, loads active workflow template via `WorkflowTemplateLoader`, filters `transitions[]` to those where `fromStatus === case.status`, evaluates guards + role visibility for each, returns array of `{id, label, toStatus, guardsPassed, failedGuards}`. If no active template, returns the empty array (admin free-form transitions go through a separate code path).
  - `execute(string $caseId, string $transitionId, ?string $comment, string $userId): array` — fetches case + transition; re-evaluates ALL guards (defence in depth, throws `GuardFailedException` on any failure); updates `case.status` to `toStatus`; creates `statusRecord` with `fromStatus`, toStatus (via `statusType`), `transitionLabel`, `description = $comment`, `evaluatedGuards`, `dispatchedActions: []`; saves case (refreshes `updatedAt`); dispatches side-effects via `SideEffectDispatcher::dispatch`; updates the `statusRecord` with the actual `dispatchedActions[]` results. Status mutation MUST happen before dispatching; dispatch failures MUST NOT roll back status.
  - `executeFreeForm(string $caseId, string $toStatusId, ?string $comment, string $userId): array` — admin-only path for caseTypes lacking an active workflow template; validates `toStatusId` belongs to `case.caseType` statusTypes; validates target is not `isFinal: true` UNLESS the caller is in the procest admin group; writes `statusRecord` with `noWorkflowTemplate: true`; NO side-effects dispatched.
  - `replay(string $caseId): array` — returns `[{ statusRecord }, ...]` ordered by `createdAt asc`; reconstructs state-progression view; does NOT re-fire side-effects.
  All methods derive user identity from `IUserSession` when `$userId` is not explicitly passed. Catch `\Throwable` boundaries, log with `$this->logger->error()`, return static error messages to controller.

---

## Backend: Controller

- [ ] **T11**: Create `lib/Controller/StatusTransitionController.php`. Endpoints:
  - `GET /api/case/{caseId}/available-transitions` — calls `StatusTransitionService::getAvailableTransitions`; returns `{transitions, current: {statusId, statusName}}`.
  - `POST /api/case/{caseId}/transition` — body `{transitionId, comment?}`; calls `StatusTransitionService::execute`; returns 200 with `{status: 'ok', statusRecord, dispatchedActions}`; returns 409 with static message on `GuardFailedException`; returns 403 with static message on role rejection; returns 404 if transition not found in active template.
  - `POST /api/case/{caseId}/transition-freeform` — body `{toStatusId, comment?}`; admin-group check via `IGroupManager`; calls `executeFreeForm`.
  - `GET /api/case/{caseId}/transition-history` — calls `replay`; returns `{history, replayable: true}`.
  NEVER return `$e->getMessage()` in `JSONResponse`. ALL logs go through `$this->logger->error()`.

- [ ] **T12**: Add routes to `appinfo/routes.php`:
  ```php
  ['name' => 'status_transition#available', 'url' => '/api/case/{caseId}/available-transitions', 'verb' => 'GET'],
  ['name' => 'status_transition#execute',   'url' => '/api/case/{caseId}/transition',             'verb' => 'POST'],
  ['name' => 'status_transition#freeform',  'url' => '/api/case/{caseId}/transition-freeform',    'verb' => 'POST'],
  ['name' => 'status_transition#history',   'url' => '/api/case/{caseId}/transition-history',     'verb' => 'GET'],
  ```

---

## Backend: Backfill of ZgwZrcRulesService

- [ ] **T13**: Slim `lib/Service/ZgwZrcRulesService.php` and `lib/Controller/ZrcController.php`:
  - `rulesStatussenCreate()` keeps the `zrc-016` ZGW validation (statustype belongs to zaaktype); after validation passes, delegate the actual `case.status` write to `StatusTransitionService::executeFreeForm` so a `statusRecord` is always written.
  - Move `ZrcController::handleEindstatusEffect()` logic into a `SetFieldHandler`-driven side-effect registered on the seeded standard workflow templates' eindstatus transitions (`einddatum`, snapshot resultaat); remove the inline call.
  - Move zrc-022 archiefstatus transition validation into a `RequiredFieldGuard` (require `archiefnominatie` + `archiefactiedatum`) on the standard workflow's `Afgehandeld → Gearchiveerd` transition.
  - Run `composer check:strict` on the slimmed-down service and fix any pre-existing PHPCS/PHPMD/Psalm/PHPStan warnings encountered (per project policy).

---

## Integration Hooks

- [ ] **T14**: Wire `bezwaar-lifecycle` integration point: register the existing bezwaar phase-change actions (advisory case creation, hearing scheduling notification) as `CreateSubCaseHandler` and `NotifyHandler` configurations on the seeded bezwaar workflow template (provided by `workflow-definition-model`). Verify by re-running the bezwaar lifecycle scenarios end-to-end.

- [ ] **T15**: Wire `parafeerroute-engine` integration point: extend the dispatcher to also accept `voorstel`-typed entities for `notify` actions, so parafering step-activation goes through the same dispatcher. Document the typed-entity extension as V1 (the spec leaves an explicit hook in REQ-STE-9).

---

## Frontend

- [ ] **T16**: Register `status-record` entity type in `src/store/store.js` via `createObjectStore('status-record')` with `relationsPlugin`. Type name MUST be kebab-case. Register ONCE.

- [ ] **T17**: Create `src/services/statusTransitionApi.js`. Functions: `getAvailableTransitions(caseId)`, `executeTransition(caseId, transitionId, comment)`, `executeFreeFormTransition(caseId, toStatusId, comment)`, `getTransitionHistory(caseId)`. Use `axios` from `@nextcloud/axios` for ALL calls (CSRF auto-attach). NEVER raw `fetch()`.

- [ ] **T18**: Create `src/views/cases/components/AvailableTransitionsPanel.vue`. Polls `getAvailableTransitions(caseId)` on case open + after any case mutation. Renders one button per available transition. Disabled buttons show tooltip with the first failed guard's failure message. Role-hidden transitions (RoleGuard with `details.silent: true`) are not rendered at all.

- [ ] **T19**: Create `src/views/cases/components/TransitionConfirmDialog.vue`. Triggered on transition-button click; shows: target status name, transition label, list of side-effects to fire (label only, no config detail), optional `comment` textarea, Cancel / Confirm. On confirm, calls `executeTransition`; on success, refreshes the case detail.

- [ ] **T20**: Embed `AvailableTransitionsPanel` into `src/views/cases/CaseDetail.vue` in the status section. Replace any existing ad-hoc status-change UI in that file.

---

## Verification

- [ ] **V01**: All requirements from `openspec/changes/status-transition-engine/specs/status-transition-engine/spec.md` (REQ-STE-1..10) have corresponding implementation in `lib/Service/StatusTransitionService.php`, the guard registry, and the side-effect dispatcher. The pre-existing `openspec/specs/status-transition-engine/spec.md` requirements (Guard Evaluation, Transition Execution, Available Transitions) are subsumed by the new REQs.

- [ ] **V02**: PHPUnit tests cover at minimum: each guard evaluator (pass + fail), `StatusTransitionService::execute` happy path, guard-failure path (no status change, no side-effects), side-effect-failure path (status changed, error logged, statusRecord has `dispatchedActions[*].ok = false`), `replay` chronological ordering, freeform admin transition with `noWorkflowTemplate: true`. `composer check:strict` and `composer test` pass.

- [ ] **V03**: Browser smoke test via Playwright MCP (`browser-1`): open a case with an active workflow template, observe `AvailableTransitionsPanel` renders allowed transitions, click a transition, complete the confirm dialog, observe the case status updates in the UI AND a `statusRecord` is written (visible in transition history). Test guard-disabled tooltip on a transition whose checklist is incomplete.

- [ ] **V04**: End-to-end ZGW API regression: `POST /zaken/v1/statussen` still validates `zrc-016` (statustype belongs to zaaktype); the resulting status change is now recorded as a `statusRecord` with `noWorkflowTemplate: true` (when no workflow template) or via `StatusTransitionService::execute` (when one exists). Existing ZGW Newman collection passes unchanged.
