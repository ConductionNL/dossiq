# Proposal: Status Transition Engine

## Summary

Implement a generic status-transition engine in Procest that drives every case through its statuses based on the `workflowTemplate` (defined by the sibling spec `workflow-definition-model`). The engine evaluates guards, enforces role-based authorisation, executes transitions atomically, dispatches side-effects (automatic actions), and writes a replayable audit log. It becomes the single write path for `case.status` — replacing scattered logic in `ZgwZrcRulesService` and `ZrcController`.

## Problem

Today status transitions live in ad-hoc PHP services. `ZgwZrcRulesService::rulesStatussenCreate` only checks the ZGW `statustype` reference; nothing enforces which status can move to which, who is allowed to make the move, or what side-effects should fire. The `workflowTemplate` schema already carries rich transition rules (guards, allowedRoles, automaticActions) but no runtime consumes them. We need a generic engine that turns workflow-definition data into deterministic runtime behaviour, surfaced uniformly to UI, REST, and the future visual workflow editor.

## Affected Projects

- [ ] Project: `procest` — Add `StatusTransitionService` (engine), `StatusTransitionController`, guard registry, side-effect dispatcher, transition-history fields on `statusRecord`; backfill `ZgwZrcRulesService` legacy rules into the engine; surface available transitions on case detail.

## Scope

### In Scope (V1)

- **Transition Rule Consumer** (REQ-STE-1): Parse `workflowTemplate.transitions` into runtime rules indexed by `caseType` + `fromStatus`.
- **Guard Registry & Evaluation** (REQ-STE-2): Pluggable `checklist`, `requiredField`, `requiredDocument`, `roleGuard` evaluators; conjunctive evaluation.
- **Available Transitions** (REQ-STE-3): Compute the set allowed for the current user; expose via `GET /api/case/{id}/available-transitions`.
- **Atomic Execution** (REQ-STE-4): Single write path that updates `case.status`, writes a `statusRecord`, and survives partial side-effect failures.
- **Side-Effect Dispatcher** (REQ-STE-5): Map `automaticActions` (`sendEmail`, `createTask`, `createSubCase`, `webhook`, `setField`, `notify`) to existing services; failed actions logged, not rolled back.
- **Audit Log & Replay** (REQ-STE-6): Every transition writes a `statusRecord` with `fromStatus`, `toStatus`, `transitionLabel`, `evaluatedGuards`, `dispatchedActions`; replay via `GET /api/case/{id}/transition-history`.
- **REST Controller** (REQ-STE-7): Endpoints for listing, executing, replaying.
- **Backfill** (REQ-STE-8): Migrate zrc-007 (eindstatus afsluiten), zrc-022 (archiefstatus) into engine-evaluated actions/guards; preserve ZGW API contract.
- **Integration Hooks** (REQ-STE-9): `bezwaar-lifecycle` and `parafeerroute-engine` register side-effect handlers via DI tagging.
- **No-Workflow Fallback** (REQ-STE-10): Admin free-form transitions on caseTypes without a workflow, flagged on the `statusRecord`.

### Out of Scope

- Visual workflow editor canvas (spec `visual-workflow-editor`).
- Parallel/branching transitions (CMMN gateways) — V2.
- Time-based / scheduled transitions — V2.
- Workflow versioning migrations for in-flight cases — separate change.

## Approach

1. Reuse existing entities (`case`, `statusType`, `statusRecord`, `workflowTemplate`); add optional fields to `statusRecord` (`transitionLabel`, `fromStatus`, `evaluatedGuards`, `dispatchedActions`, `noWorkflowTemplate`).
2. `StatusTransitionService.php` is the deterministic core — methods `getAvailableTransitions`, `execute`, `executeFreeForm`, `replay`.
3. Guards via `GuardEvaluatorInterface` strategy pattern; side-effects via `ActionHandlerInterface`; both registered through DI so downstream specs plug in without touching the engine.
4. Slim `ZgwZrcRulesService` down to ZGW spec validation; route the actual mutation through the engine.
5. Frontend: `AvailableTransitionsPanel` + `TransitionConfirmDialog` embedded in `CaseDetail.vue`.

## Cross-Project Dependencies

- **`workflow-definition-model`** (procest, in flight): Provides the `transitions[]`, `automaticActions[]`, `allowedRoles[]` schema the engine consumes.
- **OpenRegister**: `statusRecord`, `case`, `workflowTemplate`, `statusType` storage; built-in `auditTrail` and `relations`.
- **`NotificatieService`** (platform): backs `sendEmail` and `notify` action handlers.
- **`TasksController`** (procest): backs `createTask`.
- **`bezwaar-lifecycle` / `parafeerroute-engine`**: consume the engine — register side-effect handlers via DI tagging.

## Constraints

- Depends on `workflow-definition-model` (transitions JSON shape must be in place before the engine can consume it).
- Backfill of `ZgwZrcRulesService` MUST preserve the ZGW Zaken API contract; only behaviour change is the added audit-log entry.
