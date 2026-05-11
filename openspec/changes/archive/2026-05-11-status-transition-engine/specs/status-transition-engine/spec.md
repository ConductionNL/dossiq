---
status: draft
---
# status-transition-engine Specification

## Purpose

Implement the runtime engine that drives every Procest case through its statuses based on the workflow-definition data model. The engine reads `workflowTemplate.transitions[]`, evaluates guards, enforces role-based authorisation, executes transitions atomically, dispatches configured side-effects (automatic actions), and writes a replayable audit log. It is the single write path for `case.status` across the application: the REST API, the case detail UI, and the bezwaar / parafering / VTH workflow specs all funnel transitions through `StatusTransitionService::execute()`.

## Context

The `workflow-definition-model` spec defined the data shape of workflow templates: ordered steps, transitions with `fromStatus`/`toStatus`/`label`/`guards`/`automaticActions`/`allowedRoles`. Today no Procest service consumes those transitions at runtime — status changes happen through scattered hand-coded paths in `ZgwZrcRulesService` and `ZrcController`, with no guard enforcement, no automatic actions, and inconsistent `statusRecord` writes. This change closes that gap by introducing a single engine that turns workflow-definition data into deterministic runtime behaviour, surfaced uniformly to UI, REST, and the future visual workflow editor.

## ADDED Requirements
---

### Requirement: REQ-STE-1 — Transition Rule Consumption

The system SHALL load the active `workflowTemplate` for a case's `caseType` and parse its `transitions[]` JSON into runtime-usable rule objects, indexed by `fromStatus`. Only the workflow template with `isActive: true` for the given `caseType` SHALL be consulted; draft and inactive templates SHALL be ignored at runtime.

**Feature tier**: V1

#### Scenario: Load active workflow template

- **GIVEN** a `caseType` with two `workflowTemplate` objects: `version 1, isActive: false` and `version 2, isActive: true, isDraft: false`
- **WHEN** the engine is asked for available transitions on a case of that type
- **THEN** the engine SHALL load `version 2` only
- **AND** the engine SHALL decode `transitions` and `steps` from JSON
- **AND** `version 1`'s transitions SHALL NOT be considered

#### Scenario: No active template falls through to free-form

- **GIVEN** a `caseType` with no `workflowTemplate` where `isActive: true`
- **WHEN** the engine is asked for available transitions
- **THEN** the engine SHALL return an empty list of guided transitions
- **AND** admins SHALL retain the ability to set any non-final `statusType` of that case type via the free-form endpoint

---

### Requirement: REQ-STE-2 — Guard Registry and Evaluation

The system SHALL evaluate every guard declared on a transition before allowing the transition to proceed. Guard types `checklist`, `requiredField`, `requiredDocument`, and `roleGuard` SHALL be supported in V1. Guards combine conjunctively: ALL guards must pass for the transition to be available. Guard evaluation SHALL be deterministic and side-effect-free.

**Feature tier**: V1

#### Scenario: All guards pass

- **GIVEN** a transition with one `requiredField` guard on `resultaat` and one `roleGuard` allowing `Behandelaar`
- **AND** the case has `resultaat: "Toegekend"`
- **AND** the current user has role `Behandelaar` on the case
- **WHEN** the engine evaluates guards
- **THEN** the transition SHALL be reported as `guardsPassed: true`
- **AND** `failedGuards` SHALL be empty

#### Scenario: Single guard fails — transition unavailable

- **GIVEN** a transition with a `checklist` guard requiring 5 items completed
- **AND** the case has only 4 of 5 items checked
- **WHEN** the engine evaluates guards
- **THEN** the transition SHALL be reported as `guardsPassed: false`
- **AND** `failedGuards` SHALL contain one entry: `{type: 'checklist', failureMessage: "1 checklistitem niet afgevinkt: 'Besluit opgesteld'"}`

#### Scenario: Role guard hides transition

- **GIVEN** a transition restricted to role `Afdelingshoofd`
- **AND** the current user has role `Behandelaar`
- **WHEN** the engine computes available transitions for this user
- **THEN** the transition SHALL NOT appear in the returned list
- **AND** the failure SHALL be recorded with `details.silent: true` so the UI does not render a disabled button for it

#### Scenario: Required document guard

- **GIVEN** a transition with a `requiredDocument` guard for type `Besluit`
- **AND** the case has no document of type `Besluit` attached
- **WHEN** the engine evaluates guards
- **THEN** the transition SHALL be reported `guardsPassed: false`
- **AND** the failure message SHALL be "Vereist document ontbreekt: Besluit"

---

### Requirement: REQ-STE-3 — Available Transitions Computation

The system SHALL compute, for any given case and user, the set of transitions whose `fromStatus` matches the case's current `status`, whose `roleGuard` admits the user, and whose remaining guards (besides RoleGuard) are evaluated and reported with pass/fail breakdown.

**Feature tier**: V1

#### Scenario: List available transitions on case detail

- **GIVEN** a case in status `In behandeling` for a caseType whose active workflow defines two transitions from `In behandeling`: `Goedkeuren` (RoleGuard: `Afdelingshoofd`) and `Terugsturen` (no role guard)
- **WHEN** a user with role `Behandelaar` opens the case detail
- **THEN** the API SHALL return `transitions: [{id: <terugsturenId>, label: "Terugsturen", toStatus: <uuid>, guardsPassed: true, failedGuards: []}]`
- **AND** `Goedkeuren` SHALL NOT appear in the response

#### Scenario: Final status — no transitions

- **GIVEN** a case in a status with `isFinal: true`
- **WHEN** any user requests available transitions
- **THEN** the API SHALL return `transitions: []`
- **AND** the UI SHALL indicate "Zaak is afgehandeld"

---

### Requirement: REQ-STE-4 — Atomic Transition Execution

The system SHALL execute status transitions atomically: case status update, `statusRecord` creation, and `case.auditTrail` append happen as a single logical operation. Guard re-evaluation SHALL occur server-side as defence in depth; UI guard checks SHALL NOT be trusted.

**Feature tier**: V1

#### Scenario: Successful transition writes a statusRecord

- **GIVEN** a case in status `In behandeling`
- **WHEN** the case handler executes transition `Afronden` to `Afgehandeld` via `POST /api/case/{id}/transition`
- **THEN** `case.status` SHALL be updated to the `Afgehandeld` `statusType` UUID
- **AND** a `statusRecord` SHALL be created with `case`, `statusType` (toStatus), `fromStatus`, `transitionLabel: "Afronden"`, `description` (optional comment), `evaluatedGuards`, `dispatchedActions`, and OpenRegister-managed `createdAt` + `owner`
- **AND** `case.updatedAt` SHALL be refreshed
- **AND** the response SHALL be `200` with `{status: 'ok', statusRecord, dispatchedActions}`

#### Scenario: Server-side guard re-evaluation blocks stale UI

- **GIVEN** the UI shows a transition as available (cached)
- **AND** server-side a guard has since failed (e.g. a required document was removed)
- **WHEN** the user clicks the transition button and the request reaches the engine
- **THEN** the engine SHALL re-evaluate all guards
- **AND** the engine SHALL reject the request with HTTP 409 and a static failure message
- **AND** `case.status` SHALL NOT change
- **AND** NO `statusRecord` SHALL be created

---

### Requirement: REQ-STE-5 — Side-Effect Dispatching

The system SHALL dispatch all `automaticActions[]` configured on a successful transition. Action types `sendEmail`, `createTask`, `createSubCase`, `webhook`, `setField`, and `notify` SHALL be supported in V1. Actions SHALL fire **sequentially in declaration order**. Failure of any individual action SHALL be logged with full context but SHALL NOT roll back the status change.

**Feature tier**: V1

#### Scenario: Transition triggers automatic actions

- **GIVEN** transition `Goedkeuren` is configured with two actions: `sendEmail` then `createTask`
- **WHEN** the transition is successfully executed
- **THEN** the engine SHALL invoke `SendEmailHandler` first, then `CreateTaskHandler`
- **AND** both handlers SHALL receive the full transition context (`fromStatus`, `toStatus`, `transitionLabel`, `userId`, `statusRecordUuid`)
- **AND** the resulting `statusRecord.dispatchedActions` SHALL be `[{type: 'sendEmail', ok: true}, {type: 'createTask', ok: true}]`

#### Scenario: Failed action does not roll back

- **GIVEN** transition `Afronden` has a `webhook` action and a `setField` action
- **WHEN** the webhook target returns HTTP 500
- **THEN** the case status SHALL still be updated to the target status
- **AND** the `setField` action SHALL still be invoked (subsequent action)
- **AND** `statusRecord.dispatchedActions` SHALL include `{type: 'webhook', ok: false, error: <static>}` and `{type: 'setField', ok: true}`
- **AND** the error SHALL be logged via `$this->logger->error()` with full context
- **AND** the case owner SHALL receive an in-app notification: "Een automatische actie op zaak <identifier> is mislukt"

#### Scenario: Unknown action type is logged but does not abort

- **GIVEN** a transition includes an action with `type: "publishToLinkedIn"` for which no handler is registered
- **WHEN** the engine dispatches actions
- **THEN** the dispatcher SHALL record `{type: 'publishToLinkedIn', ok: false, error: 'unknown_action_type'}` in `statusRecord.dispatchedActions`
- **AND** subsequent actions in the list SHALL continue to dispatch
- **AND** a warning SHALL be logged with the unknown type

---

### Requirement: REQ-STE-6 — Transition Audit Log and Replay

The system SHALL persist every transition as a `statusRecord` linked to the case. The full chronological history of a case's transitions SHALL be replayable via a single API call. Replay SHALL NOT re-fire side-effects.

**Feature tier**: V1

#### Scenario: Replay chronological history

- **GIVEN** a case has been through three transitions: `Ontvangen → In behandeling → Afgehandeld`
- **WHEN** a user requests `GET /api/case/{id}/transition-history`
- **THEN** the API SHALL return `history: [{statusRecord1}, {statusRecord2}, {statusRecord3}]` in `createdAt asc` order
- **AND** each record SHALL include `fromStatus`, `statusType` (= toStatus), `transitionLabel`, `userId` (owner), `createdAt`, `evaluatedGuards`, `dispatchedActions`
- **AND** the response SHALL include `replayable: true`
- **AND** NO side-effect handlers SHALL be invoked during replay

---

### Requirement: REQ-STE-7 — REST Controller Surface

The system SHALL expose REST endpoints for available-transition queries, transition execution, admin free-form transitions, and history replay. ALL error responses SHALL use static failure messages. The controller SHALL NEVER return `$e->getMessage()` in a response body.

**Feature tier**: V1

#### Scenario: Endpoint table

- **GIVEN** the engine routes are registered
- **WHEN** the routes are inspected
- **THEN** the following endpoints SHALL exist:
  - `GET  /api/case/{caseId}/available-transitions`
  - `POST /api/case/{caseId}/transition` (body `{transitionId, comment?}`)
  - `POST /api/case/{caseId}/transition-freeform` (admin only; body `{toStatusId, comment?}`)
  - `GET  /api/case/{caseId}/transition-history`

#### Scenario: Authorisation enforcement

- **GIVEN** a user without the procest admin group
- **WHEN** the user calls `POST /api/case/{caseId}/transition-freeform`
- **THEN** the controller SHALL return HTTP 403 with a static message
- **AND** the user identity SHALL be derived from `IUserSession`, NEVER from the request body

---

### Requirement: REQ-STE-8 — Backfill of Legacy Status Logic

The system SHALL retire status mutation paths in `ZgwZrcRulesService` and `ZrcController`, delegating instead to the engine. Legacy ZGW API contracts SHALL be preserved: `POST /zaken/v1/statussen` continues to validate `zrc-016` (statustype belongs to zaaktype) and writes a status, but the write SHALL now flow through `StatusTransitionService::execute` (or `executeFreeForm`) so that a `statusRecord` is always emitted.

**Feature tier**: V1

#### Scenario: Eindstatus side-effects migrate to action handlers

- **GIVEN** a workflow template's eindstatus transition is configured with `setField` actions for `einddatum: now()` and `result: <snapshot>` (replacing the inline `handleEindstatusEffect` logic)
- **WHEN** the transition fires
- **THEN** the engine SHALL set both fields via `SetFieldHandler`
- **AND** the legacy `ZrcController::handleEindstatusEffect` method SHALL be removed
- **AND** existing ZGW Newman regression tests SHALL pass unchanged

#### Scenario: ZGW statussen POST emits a statusRecord

- **GIVEN** a client posts `POST /zaken/v1/statussen` with valid body
- **WHEN** the request passes `rulesStatussenCreate` validation
- **THEN** the engine SHALL be invoked (via `execute` if the target status maps to a known transition, otherwise `executeFreeForm`)
- **AND** a `statusRecord` SHALL be persisted with `noWorkflowTemplate: true` if no active template existed
- **AND** the API response SHALL remain ZGW-spec-compliant

---

### Requirement: REQ-STE-9 — Integration Hooks for Downstream Specs

The system SHALL allow downstream specs (`bezwaar-lifecycle`, `parafeerroute-engine`) to plug into transition execution by registering side-effect handlers through DI service tagging, without modifying engine source. The dispatcher SHALL be entity-aware: in addition to `case.status` transitions, it SHALL accept transitions on the configured set of typed entities (V1 includes `case`; the extension point reserves the typed-entity dispatch path for parafering's `voorstel.status` flow).

**Feature tier**: V1

#### Scenario: bezwaar-lifecycle registers createSubCase handler

- **GIVEN** the bezwaar workflow template's `Ontvankelijk → In behandeling` transition lists a `createSubCase` action
- **WHEN** the transition fires
- **THEN** the engine SHALL invoke the `CreateSubCaseHandler` registered by the `bezwaar-lifecycle` integration
- **AND** an advisory deelzaak SHALL be created linked to the primary bezwaar case via `hoofdzaak`

#### Scenario: parafeerroute-engine uses the same dispatcher

- **GIVEN** a `voorstel.status` change to `in_parafering` configured to emit a `notify` action
- **WHEN** parafeerroute-engine triggers the typed-entity transition path
- **THEN** the engine SHALL invoke `NotifyHandler` via the same dispatcher used for case transitions
- **AND** the resulting notification SHALL share the same log/error semantics as case-side-effects

---

### Requirement: REQ-STE-10 — No-Workflow Fallback for Free-Form Transitions

The system SHALL allow procest admins to transition cases whose `caseType` lacks an active workflow template to any non-final `statusType` of that caseType, with full audit logging. Free-form transitions SHALL bypass guard evaluation and side-effect dispatching but SHALL still write a `statusRecord` flagged `noWorkflowTemplate: true`.

**Feature tier**: V1

#### Scenario: Admin free-form transition

- **GIVEN** a case of a caseType with no `workflowTemplate` where `isActive: true`
- **AND** the current user is in the procest admin group
- **WHEN** the user calls `POST /api/case/{caseId}/transition-freeform` with `{toStatusId, comment}`
- **THEN** the engine SHALL validate that `toStatusId` is a `statusType` of the case's `caseType`
- **AND** the engine SHALL reject targets where `isFinal: true` UNLESS the caller is in the procest admin group (admins MAY close cases free-form)
- **AND** the engine SHALL update `case.status` to `toStatusId`
- **AND** a `statusRecord` SHALL be written with `noWorkflowTemplate: true`, `evaluatedGuards: []`, `dispatchedActions: []`
- **AND** no side-effects SHALL fire

#### Scenario: Non-admin cannot free-form

- **GIVEN** a case of a caseType with no active workflow template
- **AND** the current user is NOT in the procest admin group
- **WHEN** the user calls `POST /api/case/{caseId}/transition-freeform`
- **THEN** the engine SHALL return HTTP 403 with a static message
- **AND** `case.status` SHALL NOT change
