# Tasks: workflow-definition-model

## Deduplication Check

- [ ] **D01**: Confirm the existing `workflowTemplate` schema in `lib/Settings/procest_register.json` (lines ~1836–1899) and the existing `workflow_template_schema` config key in `lib/Service/SettingsService.php` (lines ~65, ~118). Confirm no `WorkflowDefinitionService` or `WorkflowDefinitionController` already exists under `lib/Service/` or `lib/Controller/`. Confirm `case.workflowTemplate` and `case.workflowVersion` are already present on the case schema per ADR-000.

---

## Schema & Configuration

- [ ] **T01**: Extend the `workflowTemplate` schema in `lib/Settings/procest_register.json` with a new `lifecycleStatus` enum property (`draft`, `published`, `deprecated`, default `draft`). Keep `isDraft` and `isActive` for backwards compatibility but document that `lifecycleStatus` is authoritative going forward. Bump the schema `version` field.

- [ ] **T02**: Add config key `workflow_definition_schema` (alias of `workflow_template_schema`) to `lib/Service/SettingsService.php` so consumer specs can refer to a stable name independent of the legacy schema slug. Load in `initializeStores()`.

- [ ] **T03**: Add `workflowDefinition` (UUID reference to `workflowTemplate`) to the `caseType` schema in `lib/Settings/procest_register.json`. Document that this is the pinned active definition; absence means "fall through to latest published".

---

## Backend: Service

- [ ] **T04**: Create `lib/Service/WorkflowDefinitionService.php`. Methods (all using `ObjectService` 3-arg API):
  - `createDraft(string $caseTypeId, array $data): array` — creates a new `workflowTemplate` with `lifecycleStatus: draft`, `version` = max-existing-version-for-caseType + 1.
  - `updateDraft(string $id, array $data): array` — rejects with error if `lifecycleStatus != draft`.
  - `publish(string $id): array` — atomically: sets target to `published`+`isActive: true`; deprecates any previously active version for the same caseType; updates `caseType.workflowDefinition` to point at the newly active version. Wraps in a try/catch; on failure logs and returns a static error.
  - `deprecate(string $id): array` — sets `lifecycleStatus: deprecated`, `isActive: false`. Refuses to deprecate the last published version of a caseType that has open cases (raises a static error). Logs the failure if any.
  - `clone(string $id): array` — produces a new draft from a published or deprecated version with `version` incremented.
  - `getActiveDefinitionFor(string $caseTypeId): ?array` — read-only consumer entrypoint.
  - `getDefinition(string $id): array` — read by UUID.
  - `getDefinitionForCase(string $caseId): array` — resolves via `case.workflowTemplate` + `case.workflowVersion`.
  - `listVersions(string $caseTypeId): array` — admin UI listing.
  All mutations derive user identity from `IUserSession`. Never return `$e->getMessage()` to callers; log with `$this->logger->error()` and return a static error.

- [ ] **T05**: Add publish/deprecate lifecycle invariants enforced in `WorkflowDefinitionService`:
  - Cannot edit a `published` or `deprecated` object.
  - Cannot publish a `draft` whose `transitions[]` references a `statusType` that does not belong to the linked `caseType` — validate referential integrity before flipping the status.
  - On `publish()`, the previously active version for the same caseType is moved to `deprecated`+`isActive: false` in the same logical operation.

---

## Backend: Controller

- [ ] **T06**: Create `lib/Controller/WorkflowDefinitionController.php` exposing:
  - `GET    /api/workflow-definition` — list (filter by `caseType`, `lifecycleStatus`)
  - `POST   /api/workflow-definition` — create draft (admin only via `IGroupManager`)
  - `GET    /api/workflow-definition/{id}` — read one
  - `PUT    /api/workflow-definition/{id}` — update draft (admin only; rejects non-draft)
  - `DELETE /api/workflow-definition/{id}` — delete (admin only; only `draft` versions deletable)
  - `POST   /api/workflow-definition/{id}/publish` — publish (admin only)
  - `POST   /api/workflow-definition/{id}/deprecate` — deprecate (admin only)
  - `POST   /api/workflow-definition/{id}/clone` — clone into new draft
  Never return raw exception messages — use static error strings, log internals.

---

## Routes

- [ ] **T07**: Register the eight endpoints from T06 in `appinfo/routes.php`. Use the kebab-case URL form `/api/workflow-definition[/...]` and PHP controller name `workflow_definition`.

---

## Frontend: Store + API

- [ ] **T08**: Register `workflow-definition` entity type in `src/store/store.js` via `createObjectStore('workflow-definition')` with `relationsPlugin`. Register ONCE. Add `src/services/workflowDefinitionApi.js` with all CRUD + lifecycle calls (`listVersions`, `createDraft`, `updateDraft`, `publish`, `deprecate`, `clone`, `getActiveFor`). Use `@nextcloud/axios` exclusively.

---

## Frontend: Admin UI

- [ ] **T09**: Create `src/views/settings/components/WorkflowDefinitionsTab.vue`:
  - Imports only from `@conduction/nextcloud-vue`.
  - Top-level caseType selector; below: table of versions for the selected caseType with columns: version, lifecycleStatus badge (`CnStatusBadge`), updatedAt, actions.
  - Actions: `Edit` (only for draft), `Publish` (only for draft), `Deprecate` (only for published), `Clone` (for published/deprecated). All confirmed via `CnDialog` (never `window.confirm`).
  - Empty state via `CnEmptyState`.
  - All strings via `this.t(appName, '...')`; SPDX header on line 1.

- [ ] **T10**: Create `src/views/settings/components/WorkflowDefinitionDialog.vue` for create/edit of a draft:
  - Fields: `title`, `description`, `caseType` (read-only after create).
  - Embeds `WorkflowStepsEditor` (ordered steps; per step: title, status reference, order, assigneeRole, isRequired, checklist) and `WorkflowTransitionsEditor` (per transition: fromStatus, toStatus, label, guards, allowedRoles).
  - On save calls `workflowDefinitionApi.createDraft` or `updateDraft` in try/catch with user-facing toast/dialog on error.
  - SPDX header; strings via `this.t(appName, '...')`.

---

## CaseType Integration

- [ ] **T11**: Add `workflowDefinition` reference field to the caseType admin dialog (`src/views/settings/components/CaseTypeDialog.vue` or equivalent). Picker lists only `published` definitions for that caseType. Setting it pins the caseType to a specific definition; unset means "use the latest published".

---

## Migration

- [ ] **T12**: Implement repair step `lib/Migration/BackfillWorkflowDefinitions.php` as described in `design.md` (synthesise one published `workflowTemplate` per existing caseType from its `statusType` order, set `caseType.workflowDefinition`, set `case.workflowVersion = 1` for open cases). Idempotent: skips caseTypes that already have `workflowDefinition` set.

---

## Pre-commit Verification

- [ ] **V01**: `grep -rL 'SPDX-License-Identifier' lib/Service/WorkflowDefinitionService.php lib/Controller/WorkflowDefinitionController.php src/views/settings/components/WorkflowDefinitionsTab.vue src/views/settings/components/WorkflowDefinitionDialog.vue src/services/workflowDefinitionApi.js` → zero results.

- [ ] **V02**: `grep -rn 'findObject\|saveObject\|findObjects\|deleteObject' lib/Service/WorkflowDefinitionService.php` → every call uses the 3-positional-arg API.

- [ ] **V03**: `grep -rn 'getMessage()' lib/Controller/WorkflowDefinitionController.php` → zero results. No raw exception messages in responses.

- [ ] **V04**: Manual QA — Creating a draft, editing it, publishing it, then attempting to edit it: edit MUST be rejected after publish. Cloning the published version creates a draft with `version+1`. Publishing the clone deprecates the previous active version. `caseType.workflowDefinition` updates to point at the newly active version.
