# Design: status-transition-engine

## Architecture Overview

The status-transition engine is a runtime component that turns the static rules carried by `workflowTemplate` (see `workflow-definition-model` spec) into deterministic case state changes. It is the **single write path** for `case.status` across Procest: the REST API, the case detail UI, and the bezwaar / parafering / VTH workflow specs all funnel transitions through `StatusTransitionService::execute()`.

```
CaseDetail.vue
└── AvailableTransitionsPanel.vue (renders the transitions list from API; disables buttons whose guards fail; tooltips)
    └── TransitionConfirmDialog.vue (label, target status, side-effect summary, confirm/cancel)

REST: /api/case/{id}/available-transitions, /api/case/{id}/transition, /api/case/{id}/transition-history
└── StatusTransitionController
    └── StatusTransitionService (engine)
        ├── WorkflowTemplateLoader (loads & caches active template per caseType)
        ├── GuardRegistry
        │   ├── ChecklistGuard
        │   ├── RequiredFieldGuard
        │   ├── RequiredDocumentGuard
        │   └── RoleGuard
        ├── SideEffectDispatcher
        │   ├── SendEmailHandler          → NotificatieService
        │   ├── CreateTaskHandler         → TasksController
        │   ├── CreateSubCaseHandler      → ZgwService
        │   ├── WebhookHandler            → HTTP client
        │   ├── SetFieldHandler           → ObjectService
        │   └── NotifyHandler             → NotificatieService
        └── TransitionLogger              → statusRecord + case.auditTrail
```

## File Map

### New Files

| File | Purpose |
|------|---------|
| `lib/Service/StatusTransitionService.php` | Engine core: `getAvailableTransitions`, `evaluateGuards`, `execute`, `replay`. Deterministic, no controller deps. |
| `lib/Service/WorkflowTemplateLoader.php` | Loads active `workflowTemplate` per `caseType`; decodes `transitions[]`, `steps[]`; in-memory cache per request. |
| `lib/Service/Transitions/GuardRegistry.php` | Strategy-pattern registry mapping guard `type` → `GuardEvaluatorInterface` implementations. |
| `lib/Service/Transitions/GuardEvaluatorInterface.php` | Interface: `evaluate(array $guardConfig, array $case): GuardResult`. |
| `lib/Service/Transitions/ChecklistGuard.php` | Evaluates `task.checklist` completion against required items. |
| `lib/Service/Transitions/RequiredFieldGuard.php` | Evaluates `case.{field}` non-empty against guard's required field list. |
| `lib/Service/Transitions/RequiredDocumentGuard.php` | Evaluates `case` document attachments against required document types. |
| `lib/Service/Transitions/RoleGuard.php` | Evaluates current user's role on the case against `allowedRoles[]`. |
| `lib/Service/Transitions/SideEffectDispatcher.php` | Dispatches `automaticActions[]` post-transition; per-action try/catch; collects results. |
| `lib/Service/Transitions/ActionHandlerInterface.php` | Interface: `handle(array $actionConfig, array $case, array $transitionContext): ActionResult`. |
| `lib/Service/Transitions/SendEmailHandler.php` | `sendEmail` action handler → `NotificatieService`. |
| `lib/Service/Transitions/CreateTaskHandler.php` | `createTask` action handler → existing tasks service. |
| `lib/Service/Transitions/WebhookHandler.php` | `webhook` action handler — outbound HTTP POST with case payload. |
| `lib/Service/Transitions/SetFieldHandler.php` | `setField` action handler — writes named field on the case. |
| `lib/Service/Transitions/NotifyHandler.php` | `notify` action handler → in-app Nextcloud notification. |
| `lib/Service/Transitions/CreateSubCaseHandler.php` | `createSubCase` action handler → `ZgwService` deelzaak create. |
| `lib/Controller/StatusTransitionController.php` | REST: list available transitions, execute, replay history. |
| `src/views/cases/components/AvailableTransitionsPanel.vue` | Lists guard-evaluated transitions on case detail. |
| `src/views/cases/components/TransitionConfirmDialog.vue` | Confirm transition, show side-effects summary. |
| `src/services/statusTransitionApi.js` | Frontend API client. |

### Modified Files

| File | Changes |
|------|---------|
| `lib/Settings/procest_register.json` | Add optional fields to `statusRecord`: `transitionLabel` (string), `evaluatedGuards` (array — guard result snapshots), `dispatchedActions` (array — per-action result snapshots), `fromStatus` (string, format uuid), `noWorkflowTemplate` (boolean). |
| `lib/Service/ZgwZrcRulesService.php` | Slim down `rulesStatussenCreate` to ZGW-spec validation only; delegate the actual transition to `StatusTransitionService::execute`. Move zrc-007 logic into a registered side-effect handler. |
| `lib/Controller/ZrcController.php` | Replace `handleEindstatusEffect()` inline logic with `StatusTransitionService::execute()` call. |
| `lib/Service/SettingsService.php` | Add `status_record_schema` config key (already may exist as `statusRecord` slug — verify). |
| `appinfo/routes.php` | Add status-transition routes (see below). |
| `src/views/cases/CaseDetail.vue` | Embed `AvailableTransitionsPanel`. |

## API Surface

```
GET  /api/case/{caseId}/available-transitions
     → { transitions: [{id, label, toStatus, guardsPassed: bool, failedGuards: [...]}], current: {statusId, statusName} }

POST /api/case/{caseId}/transition
     body: { transitionId: <uuid>, comment?: <string> }
     → { status: 'ok', statusRecord: {...}, dispatchedActions: [...] }
     → 409 if guards fail; 403 if role not allowed; 404 if no such transition

GET  /api/case/{caseId}/transition-history
     → { history: [{ statusRecord }, ...], replayable: true }
```

## Atomicity & Failure Model

1. `execute()` first re-evaluates all guards (defence in depth — UI may be stale).
2. `case.status` update + `statusRecord` write happen within one OpenRegister save flow. If either fails, the transition aborts with no partial state.
3. After successful status write, side-effects dispatch **sequentially in declaration order**. Each handler returns `ActionResult { ok: bool, error?: string }`.
4. Failed side-effects are logged to `statusRecord.dispatchedActions[].error` with full context and surfaced via notification to the case owner — they do NOT roll back the status change (per existing `status-transition-engine/spec.md` REQ-Transition Execution scenario).
5. Replay reads the `statusRecord` chain in chronological order and reconstructs the state-progression view; it does NOT re-fire side-effects.

## Guard Evaluation Model

Each guard config is a `{type, ...config}` object:

| Guard Type | Config Shape | Pass Condition |
|------------|--------------|----------------|
| `checklist` | `{taskId, requiredItems: [...]}` | All required checklist items on linked task are `checked: true` |
| `requiredField` | `{field: 'resultaat'}` | `case[field]` is non-empty (not null, not `""`, not `[]`) |
| `requiredDocument` | `{documentType: 'Besluit'}` | At least one document linked to the case has matching type |
| `roleGuard` | `{allowedRoles: ['Afdelingshoofd']}` | Current user has at least one matching role on this case |

Guards combine **conjunctively** — all must pass. Result includes a per-guard pass/fail breakdown so the UI can render specific error messages (e.g. "1 checklistitem niet afgevinkt: 'Besluit opgesteld'").

## Storage of Transition History

The engine writes one `statusRecord` per executed transition. Required fields on `statusRecord` (from ADR-000) plus engine additions:

- `case` (existing)
- `statusType` → toStatus (existing)
- `description` → free-form comment (existing)
- `transitionLabel` (NEW) — copied from workflowTemplate transition `label`
- `fromStatus` (NEW, optional) — UUID of the prior `statusType`; absent on first set
- `evaluatedGuards` (NEW) — JSON-encoded `[{type, passed, details}]`
- `dispatchedActions` (NEW) — JSON-encoded `[{type, ok, error?}]`
- `noWorkflowTemplate` (NEW, optional) — `true` for admin free-form transitions on caseTypes lacking an active workflow

Replay = `findObjects(register, statusRecordSchema, { case: $caseId, orderBy: createdAt asc })`. Cases also keep their `auditTrail` (OpenRegister built-in) for the same events, with the `statusRecord.uuid` as cross-reference.

## Integration with bezwaar-lifecycle and parafeerroute-engine

These specs **consume** the engine rather than reimplementing transition logic:

- `bezwaar-lifecycle` registers a `createSubCase` side-effect when the primary bezwaar status transitions to `In behandeling` (creates the advisory case linked to the parent).
- `parafeerroute-engine` registers a `notify` side-effect on `voorstel.status` transitions to dispatch step-activation notifications. (Note: voorstel status is currently a string lifecycle, not a `statusType` — engine SHALL emit hook events both for `case.status` *and* a configurable list of typed entities so parafering can plug in. V2 may unify these.)

Both specs subscribe through DI service tagging (`'procest.transition_side_effect_handler'`); the engine has no compile-time knowledge of either downstream consumer.

## Backfill Strategy

`ZgwZrcRulesService` shrinks to **pure ZGW Zaken API validation**:

- `zrc-016` (statustype belongs to zaaktype) — stays as request-shape validation.
- `zrc-007` (eindstatus afsluiten — set `einddatum`, snapshot resultaat) — moves to `SetFieldHandler` actions registered on the bezwaar/standard workflow templates.
- `zrc-022` (archiefstatus transition rules) — registered as `RequiredFieldGuard` on the `Afgehandeld → Gearchiveerd` transition for archived case types.

The legacy methods stay (now just validation) so the ZGW API contract is preserved; behaviour changes only by gaining audit-log entries.
