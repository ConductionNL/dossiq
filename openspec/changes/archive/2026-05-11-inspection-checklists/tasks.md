# Tasks: inspection-checklists

This is an **implementation-style** change. Tasks T01–T07 build the template + run schemas, services, and UI. V01–V04 are pre-commit verification gates.

## Schemas (OpenRegister)

- [ ] **T01**: Register `checklistTemplate` schema in `lib/Settings/procest_register.json`
  - **spec_ref**: `openspec/specs/inspection-checklists/spec.md#req-ic-1-checklist-template-entity`
  - **files**: `lib/Settings/procest_register.json`, `lib/Service/ConfigurationService.php`
  - Properties: `name`, `caseType`, `version`, `status` (`draft|active|retired`), `sections[]`, `items[]`, `seedKey`
  - `checklistItem` subschema: `order`, `label`, `helpText`, `responseType` (`ja_nee_nvt|tekst|getal|meerkeuze|foto|meting`), `required`, `fotoRequired` (`nooit|bij_nee|altijd`), `numericRange {min,max,unit}`, `choices[]`, `failureAction {type, template, deadlineDays}`
  - Required: `name`, `version`, `status`, `sections`
  - **acceptance**: GIVEN repair step WHEN run THEN schema exists with required properties and enums match exactly

- [ ] **T02**: Register `checklistRun` schema in `lib/Settings/procest_register.json`
  - **spec_ref**: `openspec/specs/inspection-checklists/spec.md#req-ic-2-checklist-run-entity`
  - **files**: `lib/Settings/procest_register.json`
  - Properties: `case`, `template`, `templateVersion`, `templateSnapshot` (hidden JSON), `inspector`, `startedAt`, `completedAt`, `status` (`concept|in_uitvoering|ingediend|gearchiveerd`), `responses[]`, `location {lat,lng,accuracy,source}`, `overallResult` (`conform|niet_conform|deels_conform`), `syncState` (`local|queued|synced`)
  - Required: `case`, `template`, `templateVersion`, `inspector`, `status`
  - **acceptance**: GIVEN repair step WHEN run THEN schema exists; `templateSnapshot` is hidden from default API output

## Backend service

- [ ] **T03**: Create `ChecklistService` in `lib/Service/ChecklistService.php`
  - **spec_ref**: `openspec/specs/inspection-checklists/spec.md#req-ic-3-per-item-validation`, `#req-ic-6-pass-fail-aggregation`, `#req-ic-7-conditional-follow-up`, `#req-ic-8-template-versioning`
  - **files**: `lib/Service/ChecklistService.php`, `lib/Controller/ChecklistController.php`, `appinfo/routes.php`
  - Methods: `startRun(templateId, caseId)`, `recordResponse(runId, itemId, payload)`, `submit(runId)`, `dispatchFollowUps(runId)`, `aggregateResult(runId)`, `validateResponse(item, payload)`
  - On `startRun`: freeze `templateSnapshot` from current template version
  - On `submit`: derive `overallResult`, write append-only flag, dispatch follow-ups
  - Inspector identity sourced from `IUserSession` only; reject frontend UIDs
  - **acceptance**:
    - GIVEN getal item with range 0-120 WHEN value=150 submitted THEN response rejected with `OUT_OF_RANGE`
    - GIVEN 8 items, 1 fail, 1 nvt WHEN aggregated THEN `overallResult = deels_conform`
    - GIVEN item with `failureAction.type = herinspectie` WHEN run submitted with that item failed THEN herinspectie task created on case
    - GIVEN run status `ingediend` WHEN `recordResponse` called THEN throws `RUN_IMMUTABLE`

## Frontend

- [ ] **T04**: Build Vue template editor in `src/views/settings/checklists/`
  - **spec_ref**: `openspec/specs/inspection-checklists/spec.md#req-ic-1-checklist-template-entity`
  - **files**: `src/views/settings/checklists/ChecklistTemplateList.vue`, `src/views/settings/checklists/ChecklistTemplateEditor.vue`
  - Drag-and-drop section + item reordering; per-item response-type form; `fotoRequired` toggle; `numericRange` inputs (visible only for `getal`/`meting`); `choices[]` editor (visible only for `meerkeuze`); `failureAction` dropdown + deadline input
  - Status transitions: `draft → active` via "Publiceren"; bumps version on subsequent edits
  - **acceptance**: GIVEN admin in settings WHEN they create template with 3 sections + 10 items + reorder THEN saved template matches schema

- [ ] **T05**: Build mobile run UI in `src/views/inspecties/`
  - **spec_ref**: `openspec/specs/inspection-checklists/spec.md#req-ic-2-checklist-run-entity`, `#req-ic-3-per-item-validation`, `#req-ic-4-evidence-linking`, `#req-ic-6-pass-fail-aggregation`
  - **files**: `src/views/inspecties/ChecklistRun.vue`, `src/views/inspecties/ChecklistResponseItem.vue`, `src/stores/checklistRunStore.js`
  - Renders `templateSnapshot.sections[]` with progress bar; per-item input adapts to `responseType`; photo capture binds to `mobiel-inspectie` camera handler; sticky submit button enabled only when all required items answered
  - Pass/fail badges per section + overall once run is `ingediend`
  - **acceptance**: GIVEN 8-item template WHEN 3 items answered THEN progress shows `3/8` and submit disabled

- [ ] **T06**: Build offline sync queue in `src/services/checklistSyncQueue.js`
  - **spec_ref**: `openspec/specs/inspection-checklists/spec.md#req-ic-5-offline-sync`
  - **files**: `src/services/checklistSyncQueue.js`, `src/services/indexedDbAdapter.js`
  - IndexedDB store keyed by `runId`; `syncState` per response + per photo; drain order: responses first (idempotent by `{runId, itemId}`), then photos (chunked, exponential backoff)
  - Conflict detection: if server `{runId, itemId}.updatedAt > local.queuedAt` AND values differ, surface chooser; never silently overwrite
  - **acceptance**:
    - GIVEN offline run with 16 responses + 6 photos WHEN reconnect THEN sync drains responses before photos
    - GIVEN server conflict on item WHEN sync runs THEN conflict surfaces; no silent overwrite

- [ ] **T07**: Build evidence upload pipeline in `lib/Service/ChecklistEvidenceService.php`
  - **spec_ref**: `openspec/specs/inspection-checklists/spec.md#req-ic-4-evidence-linking`
  - **files**: `lib/Service/ChecklistEvidenceService.php`, `lib/Controller/ChecklistController.php`
  - Photos + audio routed to `/Procest/Zaken/{caseId}/Inspecties/{runId}/items/{itemId}/` via `IRootFolder`
  - Response holds Nextcloud file IDs (not paths)
  - Post-submit writes blocked (append-only enforcement at controller level mirrors service-level check)
  - **acceptance**:
    - GIVEN run in `in_uitvoering` WHEN photo uploaded for item X THEN file lands under correct case folder and ID stored on response
    - GIVEN run in `ingediend` WHEN evidence upload attempted THEN 403 returned

## Pre-commit verification

- [ ] **V01**: `openspec validate inspection-checklists --type change --strict` → exit code 0
- [ ] **V02**: `openspec change show inspection-checklists --json --deltas-only | jq '.deltaCount'` → ≥ 8 (one delta per REQ-IC-1..8)
- [ ] **V03**: Every REQ-IC-* in `specs/inspection-checklists/spec.md` carries at least one `#### Scenario:` block
- [ ] **V04**: No files modified outside `openspec/changes/inspection-checklists/` (verify with `git diff --name-only origin/development...HEAD`)
