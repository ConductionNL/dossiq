---
status: draft
---
# workflow-definition-model Specification

## Purpose

Establish the declarative workflow-definition contract for Procest. A `workflowTemplate` aggregates the steps, transitions, guards, allowed roles, and automatic actions for a single `caseType` and is the single source of truth that `status-transition-engine`, `role-based-step-routing`, `visual-workflow-editor`, and `workflow-import-export` all consume. This change formalises the entity already present in `procest_register.json`, adds a draft → published → deprecated lifecycle with immutability after publish, introduces version pinning on `caseType`, and ships an admin UI for tenant administrators.

## Context

The `workflowTemplate` schema is in `lib/Settings/procest_register.json` and the legacy `openspec/specs/workflow-definition-model/spec.md` already describes the data shape and a seeded bezwaar/beroep workflow. What is missing is the surface that turns the schema into a usable, governed configuration object: CRUD service, lifecycle, REST endpoints, admin UI, and migration of legacy implicit lifecycles. Today, case lifecycles are implicit in `statusType` ordering and scattered button logic, which makes them impossible to test, version, or hand to a tenant admin.

## Requirements

---

### REQ-WDM-1: Declarative Workflow Definition Entity

The system SHALL expose a declarative `workflowTemplate` (aliased `WorkflowDefinition`) entity that aggregates the entire lifecycle of one `caseType`: ordered `steps[]`, allowed `transitions[]`, embedded guards, `allowedRoles`, and `automaticActions`. The entity SHALL be stored as a single OpenRegister object.

**Feature tier**: V1

#### REQ-WDM-1-001: Definition stores steps and transitions in one object

- **GIVEN** an administrator has created a workflow definition for caseType "Omgevingsvergunning"
- **WHEN** the definition is saved
- **THEN** a single `workflowTemplate` object SHALL exist in OpenRegister
- **AND** the object SHALL carry `steps` (JSON-encoded array) and `transitions` (JSON-encoded array)
- **AND** every `transitions[].fromStatus` and `transitions[].toStatus` SHALL reference a `statusType` UUID that belongs to the linked `caseType`
- **AND** every `steps[].status` SHALL reference a `statusType` UUID that belongs to the linked `caseType`

#### REQ-WDM-1-002: Definition is the single source of lifecycle truth

- **GIVEN** a case bound to a published workflow definition
- **WHEN** `status-transition-engine` or `role-based-step-routing` computes available actions for the current user
- **THEN** these consumers SHALL read transitions and roles ONLY from the bound workflow definition
- **AND** no other source (hard-coded button list, scattered controller logic) SHALL contribute additional transitions or steps

---

### REQ-WDM-2: CRUD Service

The system SHALL provide a `WorkflowDefinitionService` exposing create, read, update, delete, and lifecycle operations for `workflowTemplate` objects. All mutations SHALL derive the actor from `IUserSession`; no caller-supplied identity SHALL be accepted.

**Feature tier**: V1

#### REQ-WDM-2-001: Create a new draft

- **GIVEN** an administrator submits a request to create a workflow definition for caseType X
- **WHEN** `WorkflowDefinitionService::createDraft($caseTypeId, $data)` is invoked
- **THEN** a `workflowTemplate` SHALL be persisted with `lifecycleStatus: draft`, `isDraft: true`, `isActive: false`, and `version` set to (max existing version for caseType X) + 1
- **AND** if no prior version exists, `version` SHALL equal 1

#### REQ-WDM-2-002: Update a draft

- **GIVEN** a workflow definition with `lifecycleStatus: draft`
- **WHEN** `updateDraft($id, $data)` is invoked with new steps/transitions
- **THEN** the object SHALL be updated in OpenRegister
- **AND** referential integrity SHALL be revalidated (every status reference belongs to the linked caseType)

#### REQ-WDM-2-003: Delete restricted to drafts

- **GIVEN** an administrator attempts to delete a workflow definition
- **WHEN** the definition has `lifecycleStatus` other than `draft`
- **THEN** the operation SHALL be rejected with a static error message
- **AND** no object SHALL be deleted

---

### REQ-WDM-3: REST API

The system SHALL expose authenticated REST endpoints under `/api/workflow-definition` for definition CRUD and lifecycle operations. Endpoints SHALL never return raw exception messages.

**Feature tier**: V1

#### REQ-WDM-3-001: Endpoint contract

- **WHEN** the controller is registered
- **THEN** the following endpoints SHALL respond on the listed verbs:
  - `GET /api/workflow-definition` (list, supports `caseType` and `lifecycleStatus` filters)
  - `POST /api/workflow-definition` (create draft — admin only)
  - `GET /api/workflow-definition/{id}` (read one)
  - `PUT /api/workflow-definition/{id}` (update draft — admin only)
  - `DELETE /api/workflow-definition/{id}` (delete draft — admin only)
  - `POST /api/workflow-definition/{id}/publish` (publish — admin only)
  - `POST /api/workflow-definition/{id}/deprecate` (deprecate — admin only)
  - `POST /api/workflow-definition/{id}/clone` (clone into new draft)

#### REQ-WDM-3-002: No raw exception leakage

- **WHEN** any controller method handles a `\Throwable`
- **THEN** the response SHALL contain a static, human-readable error message
- **AND** the actual exception SHALL be logged via `$this->logger->error()` with the full message and trace

---

### REQ-WDM-4: Draft → Published → Deprecated Lifecycle

The system SHALL implement a strict three-state lifecycle for every workflow definition: `draft`, `published`, `deprecated`. Transitions between states SHALL be one-directional except for `clone` which forks a new draft.

**Feature tier**: V1

#### REQ-WDM-4-001: Allowed lifecycle transitions

- **GIVEN** a workflow definition in some `lifecycleStatus`
- **WHEN** a lifecycle operation is invoked
- **THEN** only the following transitions SHALL be permitted:
  - `draft` → `published` via `publish()`
  - `published` → `deprecated` via `deprecate()`
  - `published` → new `draft` (separate object) via `clone()`
  - `deprecated` → new `draft` (separate object) via `clone()`
- **AND** any other transition SHALL be rejected with a static error

#### REQ-WDM-4-002: Published versions are immutable

- **GIVEN** a workflow definition with `lifecycleStatus: published` or `lifecycleStatus: deprecated`
- **WHEN** any caller attempts `updateDraft($id, ...)` or `delete($id)`
- **THEN** the operation SHALL be rejected with a static error
- **AND** the persisted object SHALL be unchanged

---

### REQ-WDM-5: Publish Operation

Publishing a draft SHALL be a single atomic operation that promotes the draft, deprecates any previously active version of the same `caseType`, and re-points the `caseType.workflowDefinition` reference to the newly active version.

**Feature tier**: V1

#### REQ-WDM-5-001: Publish atomically promotes and deprecates

- **GIVEN** caseType X has workflow definition v2 with `lifecycleStatus: published, isActive: true`
- **AND** a new draft v3 exists for caseType X
- **WHEN** `publish(v3)` is invoked
- **THEN** v3 SHALL become `published`, `isActive: true`
- **AND** v2 SHALL become `deprecated`, `isActive: false`
- **AND** `caseType.workflowDefinition` SHALL point at v3
- **AND** if any step in the operation fails, the system SHALL not leave both v2 and v3 active

#### REQ-WDM-5-002: Publish validates referential integrity

- **GIVEN** a draft definition whose `transitions[]` contains a `statusType` UUID that does not belong to the linked `caseType`
- **WHEN** `publish($id)` is invoked
- **THEN** publication SHALL be rejected
- **AND** the user SHALL see a static error: "Workflow bevat statussen die niet bij dit zaaktype horen"

---

### REQ-WDM-6: Deprecate Operation

The system SHALL allow deprecating a published definition. Deprecated definitions SHALL not back new cases but SHALL continue to back existing in-flight cases.

**Feature tier**: V1

#### REQ-WDM-6-001: Deprecate marks inactive but preserves existing cases

- **GIVEN** workflow definition v2 is `published, isActive: true` for caseType X
- **AND** five open cases of caseType X are bound to v2 via `case.workflowVersion = 2`
- **WHEN** `deprecate(v2)` is invoked AND no other published version exists for caseType X
- **THEN** the deprecation SHALL be rejected with a static error referencing the open cases
- **AND** no field SHALL change on v2

#### REQ-WDM-6-002: Deprecation succeeds when another published version exists

- **GIVEN** workflow definition v2 (`published, isActive: true`) and v3 (`published, isActive: false`) both exist for caseType X
- **WHEN** `publish(v3)` is invoked
- **THEN** v2 SHALL become `deprecated, isActive: false` automatically per REQ-WDM-5-001
- **AND** open cases pinned to v2 SHALL continue to load v2 via `case.workflowVersion = 2`

---

### REQ-WDM-7: Versioning and Case Pinning

The system SHALL pin each case to a specific workflow version at the moment the case is created. New cases SHALL bind to the currently active published version; subsequent definition edits SHALL NOT affect in-flight cases.

**Feature tier**: V1

#### REQ-WDM-7-001: Case binds to active version on creation

- **GIVEN** caseType X has workflow definition v3 with `lifecycleStatus: published, isActive: true`
- **WHEN** a new case of caseType X is created
- **THEN** `case.workflowTemplate` SHALL be set to v3's UUID
- **AND** `case.workflowVersion` SHALL be set to `3`

#### REQ-WDM-7-002: Existing cases keep their pinned version after a new publish

- **GIVEN** a case pinned to v3 (`case.workflowVersion = 3`)
- **AND** the administrator subsequently publishes v4
- **WHEN** `status-transition-engine` or `role-based-step-routing` loads the workflow for that case
- **THEN** `getDefinitionForCase($caseId)` SHALL return v3, not v4
- **AND** the transition buttons and step visibility SHALL reflect v3's configuration

#### REQ-WDM-7-003: CaseType.workflowDefinition pin overrides "latest published"

- **GIVEN** caseType X has `workflowDefinition` set to a specific version v2 (a hand-pinned override)
- **WHEN** a new case of caseType X is created
- **THEN** the case SHALL be pinned to v2 regardless of which version is currently `isActive`

---

### REQ-WDM-8: Admin UI

The system SHALL provide an admin settings tab where tenant administrators can list workflow definitions per `caseType`, create drafts, edit drafts, publish, deprecate, and clone versions. All confirmations SHALL use `CnDialog` (never `window.confirm`).

**Feature tier**: V1

#### REQ-WDM-8-001: Admin tab lists versions per caseType

- **GIVEN** the administrator opens "Settings → Workflows"
- **AND** selects caseType "Omgevingsvergunning" from the top selector
- **THEN** a table SHALL list every workflow definition version for that caseType
- **AND** each row SHALL show: version number, `lifecycleStatus` badge, `isActive` indicator, `updatedAt`, action buttons

#### REQ-WDM-8-002: Actions available per lifecycleStatus

- **GIVEN** the rendered version table
- **THEN** rows in `draft` SHALL expose: Edit, Publish, Delete
- **AND** rows in `published` SHALL expose: Deprecate, Clone
- **AND** rows in `deprecated` SHALL expose: Clone

#### REQ-WDM-8-003: Edit dialog supports steps and transitions

- **GIVEN** the administrator clicks "Edit" on a draft
- **THEN** a dialog SHALL open with: title, description, an ordered steps editor, and a transitions editor
- **AND** every reference to a `statusType` in either editor SHALL be picked from a dropdown scoped to the linked caseType's statusTypes
- **AND** saving SHALL call `PUT /api/workflow-definition/{id}` in try/catch with user-facing error feedback

---

### REQ-WDM-9: Backfill Migration

The system SHALL provide a one-time idempotent migration that creates one `workflowTemplate` per existing `caseType` from the implicit ordering of its `statusType` records and pins existing open cases to that synthesised definition.

**Feature tier**: V1

#### REQ-WDM-9-001: Backfill synthesises one definition per caseType

- **GIVEN** a tenant upgrade where caseType X has no `workflowDefinition` set
- **AND** caseType X has six `statusType` records ordered by `order`
- **WHEN** the repair step `BackfillWorkflowDefinitions` runs
- **THEN** a `workflowTemplate` SHALL be created for caseType X with `version: 1`, `lifecycleStatus: published`, `isActive: true`
- **AND** `steps[]` SHALL contain one step per non-final statusType in the original order
- **AND** `transitions[]` SHALL contain a transition from each statusType to the next in the ordering with `guards: []` and `allowedRoles: []`
- **AND** `caseType.workflowDefinition` SHALL be set to the new template UUID

#### REQ-WDM-9-002: Backfill pins existing open cases

- **GIVEN** caseType X has eight open cases without `workflowVersion` set when the migration runs
- **THEN** every open case SHALL receive `case.workflowTemplate` = the synthesised template UUID
- **AND** every open case SHALL receive `case.workflowVersion = 1`

#### REQ-WDM-9-003: Backfill is idempotent

- **GIVEN** the repair step has already run for caseType X
- **WHEN** the same repair step runs again (e.g. next app upgrade)
- **THEN** caseType X SHALL be skipped — no new workflow definition SHALL be created
- **AND** the existing `workflowDefinition` reference SHALL be preserved

---

### REQ-WDM-10: Stable Consumer Contract

The system SHALL expose a stable read-only API consumed by `status-transition-engine` and `role-based-step-routing`. Consumers SHALL never reach into OpenRegister directly to read workflow data.

**Feature tier**: V1

#### REQ-WDM-10-001: Read API surface

- **WHEN** a consumer needs workflow data
- **THEN** it SHALL use exclusively one of:
  - `WorkflowDefinitionService::getActiveDefinitionFor(string $caseTypeId): ?array`
  - `WorkflowDefinitionService::getDefinition(string $id): array`
  - `WorkflowDefinitionService::getDefinitionForCase(string $caseId): array`
  - `WorkflowDefinitionService::listVersions(string $caseTypeId): array`
- **AND** no consumer SHALL invoke `ObjectService::findObject(...)` on the `workflowTemplate` schema directly

#### REQ-WDM-10-002: getDefinitionForCase resolves pinned version

- **GIVEN** a case with `case.workflowTemplate = T` and `case.workflowVersion = 2`
- **WHEN** `getDefinitionForCase($caseId)` is invoked
- **THEN** the method SHALL return the `workflowTemplate` UUID T's `version: 2` object
- **AND** the returned object SHALL include the resolved `lifecycleStatus` so consumers can warn when a case is on a deprecated version
