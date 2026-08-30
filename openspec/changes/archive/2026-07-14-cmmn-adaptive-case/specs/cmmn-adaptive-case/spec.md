# cmmn-adaptive-case Specification

**Status:** proposed
**Scope:** procest

## Purpose

Provide a second, adaptive case-handling model (CMMN) alongside procest's existing structured
BPMN-style workflow engine, so a case worker can activate optional tasks and let sentries react to
how a case actually evolves, for case types where a predetermined transition graph does not fit.
Case-model definitions are OR objects (data); the runtime lifecycle evaluator
(`CaseModelEngine`) is a pure, deterministic, exhaustively-tested engine with a single OR write
path per case.

## ADDED Requirements

### Requirement: REQ-CMMN-001 — Case-Model Definition

A `caseModel` OR object SHALL define, for one `caseType`, a set of `caseFileItems` and a tree of
`planItems` (`stage`/`humanTask`/`milestone`), each carrying a `discretionary` flag and
`entryCriteria`/`exitCriteria` sentry arrays, following the `lifecycleStatus: draft|published|
deprecated` convention `workflowTemplate` already uses (exactly one `published` model active per
`caseType`).

#### Scenario: CaseModelLoader resolves the active model for a caseType

- **GIVEN** two `caseModel` objects for the same `caseType`, one `lifecycleStatus: deprecated` and
  one `lifecycleStatus: published`
- **WHEN** `CaseModelLoader::getActiveModel($caseTypeId)` is called
- **THEN** it SHALL return the `published` model only

#### Scenario: A caseType with no published caseModel

- **GIVEN** a `caseType` with `handlingModel: cmmn` and no `published` `caseModel` object
- **WHEN** `CaseModelLoader::getActiveModel($caseTypeId)` is called
- **THEN** it SHALL return `null`, and `CaseModelEngine::getCasePlan()` SHALL surface this as an
  empty plan rather than throwing

### Requirement: REQ-CMMN-002 — Plan-Item Lifecycle State Machine

`CaseModelEngine` SHALL track each plan item's lifecycle state per the CMMN state model in
`design.md` §3: `stage`/`humanTask` items move `available → enabled → active →
completed|terminated`, with `available|enabled → disabled` reachable only via a discretionary
item's containing stage exiting before it was enabled; `milestone` items move `available →
completed|terminated` directly. Every transition SHALL be validated against an exhaustive legal-
transition table; any transition not in that table SHALL throw
`IllegalPlanItemTransitionException`, never silently no-op.

#### Scenario: Every legal transition succeeds

- **GIVEN** a `humanTask` plan item in state `available`
- **WHEN** the engine transitions it `available → enabled → active → completed`, one hop at a time
- **THEN** each hop SHALL succeed and the item's recorded state SHALL match the target of that hop

#### Scenario: An illegal transition throws

- **GIVEN** a `humanTask` plan item in the terminal state `completed`
- **WHEN** the engine is asked to transition it to any other state (including `completed` again)
- **THEN** it SHALL throw `IllegalPlanItemTransitionException` and the item's recorded state SHALL
  remain `completed`, unchanged

#### Scenario: A discretionary item never enabled is disabled when its stage exits

- **GIVEN** a discretionary `humanTask` in state `available` inside a `stage` whose only mandatory
  child just reached `completed`
- **WHEN** the engine re-evaluates the stage and finds no other non-terminal mandatory children
- **THEN** the stage SHALL transition to `completed` and the discretionary item SHALL transition to
  `disabled` in the same engine pass

#### Scenario: A milestone has no active/enabled state

- **GIVEN** a `milestone` plan item in state `available` whose entry sentry has just fired
- **WHEN** the engine evaluates the sentry
- **THEN** the milestone SHALL transition directly `available → completed` (no intermediate
  `enabled`/`active` state is ever recorded for a milestone)

### Requirement: REQ-CMMN-003 — Sentry Evaluation

`CaseModelEngine` SHALL evaluate `entryCriteria`/`exitCriteria` sentries per `design.md` §4: a
sentry fires when its `onPart` (if present) has occurred AND its `ifPart` (if present) evaluates
true (AND within one sentry); an item's entry (or exit) is satisfied when any sentry in its
criteria array fires (OR across sentries). Entry-sentry satisfaction on an `available` item
transitions it to `enabled` (cascading to `active` for mandatory items in the same pass); exit-
sentry satisfaction on any non-terminal item forces it to `terminated`.

#### Scenario: A single-condition entry sentry enables its item

- **GIVEN** a mandatory `humanTask` with one entry sentry
  `{ onPart: { planItem: "registerReport", standardEvent: "complete" } }`
- **WHEN** `registerReport` transitions to `completed`
- **THEN** the `humanTask` SHALL transition `available → enabled → active` in the same engine pass

#### Scenario: A multi-part sentry requires both onPart and ifPart

- **GIVEN** a discretionary `humanTask` with one entry sentry combining
  `onPart: { caseFileItem: "urgent", caseFileEvent: "set" }` and
  `ifPart: { field: "urgent", operator: "eq", value: true }`
- **WHEN** `signalCaseFileEvent()` sets `urgent = false`
- **THEN** the item SHALL remain `available` (onPart fired but ifPart failed)
- **WHEN** `signalCaseFileEvent()` subsequently sets `urgent = true`
- **THEN** the item SHALL transition to `enabled`

#### Scenario: Multiple entry sentries are OR'd

- **GIVEN** an item with two entry sentries, each referencing a different sibling plan item's
  `complete` event
- **WHEN** only one of the two sibling items completes
- **THEN** the item's entry criteria SHALL be satisfied and it SHALL transition to `enabled`

#### Scenario: An exit sentry terminates an active item

- **GIVEN** a `humanTask` in state `active` and an exit sentry referencing a case-file event
- **WHEN** that case-file event is signalled
- **THEN** the `humanTask` SHALL transition `active → terminated`

### Requirement: REQ-CMMN-004 — Discretionary Item Enablement

`CaseModelEngine::getEnableableDiscretionaryItems()` SHALL return exactly the plan items that are
`discretionary: true`, currently in state `enabled`, and whose parent stage is currently `active`.
Enabling a discretionary item (the "enable" REST action) SHALL transition it `enabled → active`
and SHALL be rejected with `IllegalPlanItemTransitionException` if the item is not discretionary
or not currently `enabled`.

#### Scenario: A satisfied discretionary item is surfaced as enable-able

- **GIVEN** a discretionary item whose entry sentry has fired (state `enabled`) inside an `active`
  stage
- **WHEN** `getEnableableDiscretionaryItems()` is called
- **THEN** the item SHALL be present in the result

#### Scenario: A discretionary item outside an active stage is not enable-able

- **GIVEN** a discretionary item in state `enabled` whose parent stage is `available` (not yet
  started)
- **WHEN** `getEnableableDiscretionaryItems()` is called
- **THEN** the item SHALL NOT be present in the result

#### Scenario: Enabling a mandatory item is rejected

- **GIVEN** a mandatory (`discretionary: false`) plan item in state `enabled`
- **WHEN** the "enable a discretionary item" action is invoked on it
- **THEN** it SHALL throw `IllegalPlanItemTransitionException` and leave the item's state
  unchanged

### Requirement: REQ-CMMN-005 — Milestone Achievement

`CaseModelEngine` SHALL record milestone achievement (`available → completed`) as an entry in
`casePlanState.milestones` keyed by plan-item id, with an achievement timestamp, distinct from and
never written to the pre-existing `milestoneRecord` schema.

#### Scenario: Achieving a milestone records it in casePlanState

- **GIVEN** a milestone whose entry sentry fires
- **WHEN** the engine transitions it to `completed`
- **THEN** `casePlanState.milestones[<milestoneId>].achieved` SHALL be `true` with a non-empty
  `achievedAt` timestamp
- **AND** no `milestoneRecord` OR object SHALL be created as a side effect

### Requirement: REQ-CMMN-006 — Single OR Write Path

All CMMN runtime state for a case SHALL live in the `case.casePlanState` field, written via a
single `ObjectService::saveObject()` call per engine mutation (get-plan, enable, complete,
terminate, signal). No plan item SHALL be persisted as its own OR object.

#### Scenario: A single mutation issues exactly one save

- **GIVEN** a case-file signal that trips two sentries (enabling one item and completing a
  milestone) in the same call
- **WHEN** `signalCaseFileEvent()` is invoked
- **THEN** exactly one `ObjectService::saveObject()` call SHALL be made for the case, carrying
  both resulting state changes

### Requirement: REQ-CMMN-007 — REST Surface

`CmmnCaseController` SHALL expose: `GET` the current case plan (items, states, enable-able
discretionary items); `POST` enable a discretionary item; `POST` complete or terminate a human
task; `POST` signal a case-file/event. Every endpoint SHALL require authentication and, for
mutating endpoints, SHALL enforce the same OR-RBAC group-authorization convention
`StatusTransitionService` uses (`design.md` §6) before invoking the engine.

#### Scenario: Unauthenticated request is rejected

- **GIVEN** no active user session
- **WHEN** any CMMN endpoint is called
- **THEN** it SHALL respond `401`

#### Scenario: A group-gated action is rejected for an unauthorized user

- **GIVEN** a plan item with `authorization: ["procest-enforcement"]` and a user who belongs to no
  listed group and is not an admin
- **WHEN** that user calls the "complete" endpoint for that item
- **THEN** it SHALL respond `403` and the engine SHALL NOT be invoked

#### Scenario: Getting the case plan returns items, states, and enable-able discretionary items

- **GIVEN** an authenticated user permitted to read the case
- **WHEN** `GET` the case plan is called
- **THEN** the response SHALL include every plan item with its current state, grouped by stage,
  and a distinct list of currently enable-able discretionary item ids

### Requirement: REQ-CMMN-008 — BPMN/CMMN Coexistence

A `caseType.handlingModel` of `bpmn` (default) SHALL leave `StatusTransitionService`/
`WorkflowTemplateLoader` as the sole write path for `case.status`; a value of `cmmn` SHALL make
`CaseModelEngine` the sole write path for `case.casePlanState`. `CaseModelEngine` SHALL refuse to
operate on a case whose `caseType.handlingModel !== 'cmmn'`.

#### Scenario: CaseModelEngine refuses a BPMN-managed case

- **GIVEN** a case whose `caseType.handlingModel` is `bpmn` (or unset)
- **WHEN** any `CaseModelEngine` mutating method is called for that case
- **THEN** it SHALL throw `RuntimeException('case_not_cmmn_managed')` and make no write

#### Scenario: A BPMN case type is unaffected by this change

- **GIVEN** an existing `caseType` with no `handlingModel` value set and an active
  `workflowTemplate`
- **WHEN** `StatusTransitionService::getAvailableTransitions()` is called for one of its cases
- **THEN** the result is identical to before this change (no `caseModel` lookup occurs, no
  `casePlanState` field is read or written)

### Requirement: REQ-CMMN-009 — Case-Driven End-To-End Run

A real case of a `cmmn`-handled `caseType` SHALL be drivable end-to-end through the plan: stage
activation on case start, a discretionary item enabled via a sentry trip, that item completed by a
worker, and a milestone achieved as a consequence — proving the engine is wired to a live case, not
orphaned.

#### Scenario: End-to-end adaptive case run

- **GIVEN** a `caseModel` with one root stage containing one mandatory `humanTask`, one
  discretionary `humanTask` gated by a case-file-event entry sentry, and one milestone gated by
  the discretionary task's completion
- **WHEN** the case is created (root stage and mandatory task become `available`/`enabled`/
  `active`), a case-file event is signalled that satisfies the discretionary task's entry sentry,
  the discretionary task is enabled and then completed
- **THEN** the milestone SHALL transition to `completed` and `casePlanState.milestones` SHALL
  record its achievement, all via the single `case.casePlanState` write path
