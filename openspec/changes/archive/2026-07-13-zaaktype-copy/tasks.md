# Tasks: zaaktype-copy

## Deduplication Check

- [x] **DC01**: Searched `openspec/specs/case-types/spec.md` -- REQ-CT-01
  covers create/read/update/delete of case types (Scenario CT-01c/CT-01d:
  delete blocked only by active-case count, no draft/published guard).
  No existing "duplicate"/"copy" scenario anywhere in the capability. This
  change adds copy as new behaviour and tightens delete with a new guard;
  no overlap to reconcile.
- [x] **DC02**: `CaseDefinitionExportService`/`CaseDefinitionImportService`
  (ZIP portability for DTAP pipelines) are NOT reused for the copy path --
  `CaseDefinitionExportService::exportComponent()` is a stub that returns
  hardcoded placeholder arrays, not real OpenRegister data. The copy
  service instead follows the `TemplateLibraryService::activateTemplate()`
  / `BesluitvormingTemplateService` pattern (real `ObjectService::findAll`/
  `saveObject` calls against `SettingsService`-resolved register/schema).

## 1. Backend: Copy Service

- [x] **T01**: `lib/Service/CaseTypeCopyService.php` -- `copy(string
  $caseTypeId): ?array` fetches the source `caseType` via
  `ObjectService::find()`, returns `null` when not found.
  - `@spec openspec/changes/zaaktype-copy/tasks.md#T01`
- [x] **T02**: Builds the new case type payload: strips `id`/`@self`,
  prefixes `title` with `Copy of `, forces `isDraft: true`, clears
  `publicationRequired`/`publicationText`, unsets `workflowDefinition`,
  clears `relatedCaseTypes`/`subCaseTypes`, generates a fresh `identifier`.
  - `@spec openspec/changes/zaaktype-copy/tasks.md#T02`
- [x] **T03**: Saves the new case type via `ObjectService::saveObject()`
  (no uuid -> CREATE).
  - `@spec openspec/changes/zaaktype-copy/tasks.md#T03`
- [x] **T04**: For each owned sub-schema (`status_type_schema`,
  `result_type_schema`, `role_type_schema`,
  `property_definition_schema`, `document_type_schema`,
  `decision_type_schema`), finds all objects filtered by `caseType` ==
  source id via `ObjectService::findAll()`, strips identity fields, sets
  `caseType` to the new case type's id, and saves each as a new object.
  - `@spec openspec/changes/zaaktype-copy/tasks.md#T04`

## 2. Backend: Guarded Delete

- [x] **T05**: `CaseTypeCopyService::deleteDraft(string $caseTypeId):
  array{ok: bool, reason?: string}` -- `not_found` when the source does
  not resolve, `published` when `isDraft !== true`, otherwise deletes via
  `ObjectService::deleteObject()`.
  - `@spec openspec/changes/zaaktype-copy/tasks.md#T05`

## 3. Backend: Controller + Routes

- [x] **T06**: `CaseDefinitionController::copy(string $id)` -- calls the
  service, 404 on `null`, 200 + the new case type on success.
  - `@spec openspec/changes/zaaktype-copy/tasks.md#T06`
- [x] **T07**: `CaseDefinitionController::delete(string $id)` -- maps
  `not_found` -> 404, `published` -> 409, success -> 200.
  - `@spec openspec/changes/zaaktype-copy/tasks.md#T07`
- [x] **T08**: `appinfo/routes.php` -- register `caseDefinition#copy`
  (`POST /api/case-definitions/{id}/copy`) and `caseDefinition#delete`
  (`DELETE /api/case-definitions/{id}`) after the existing literal
  export/validate/import routes.
  - `@spec openspec/changes/zaaktype-copy/tasks.md#T08`

## 4. Frontend

- [x] **T09**: `CaseTypeList.vue` -- "Duplicate" `NcButton` in row actions
  (next to Set as default / Delete); calls the copy endpoint, emits
  `select` with the new id on success so `CaseTypeAdmin.vue` navigates to
  the new draft; surfaces errors inline like the existing delete flow.
  - `@spec openspec/changes/zaaktype-copy/tasks.md#T09`
- [x] **T10**: `CaseTypeList.vue::confirmDelete()` -- final case-type
  removal now calls the guarded `DELETE /api/case-definitions/{id}`
  endpoint instead of `objectStore.deleteObject('caseType', ...)`; a 409
  response surfaces "unpublish first" error copy without removing the row.
  - `@spec openspec/changes/zaaktype-copy/tasks.md#T10`
- [x] **T11**: `CaseTypeDetail.vue` -- "Duplicate" header action (hidden
  on create); emits `duplicated` with the new id.
  - `@spec openspec/changes/zaaktype-copy/tasks.md#T11`
- [x] **T12**: `CaseTypeAdmin.vue` -- handles `@duplicated` the same way as
  `@saved` (navigate to the new id's detail view).
  - `@spec openspec/changes/zaaktype-copy/tasks.md#T12`

## 5. Tests

- [x] **T13**: `tests/Unit/Service/CaseTypeCopyServiceTest.php` -- happy
  path deep copy (case type + every sub-schema re-parented), 404 on
  missing source, guarded delete (not_found/published/happy path).
  - `@spec openspec/changes/zaaktype-copy/tasks.md#T13`
- [x] **T14**: `tests/Unit/Controller/CaseDefinitionControllerTest.php` --
  `copy()` 200/404, `delete()` 200/404/409.
  - `@spec openspec/changes/zaaktype-copy/tasks.md#T14`
- [x] **T15**: Full `phpunit -c phpunit-unit.xml` and `npm run test` (vitest)
  suites green, including any pre-existing failures fixed along the way.
  - `@spec openspec/changes/zaaktype-copy/tasks.md#T15`

## 6. Build

- [x] **T16**: `npm run build` succeeds.
  - `@spec openspec/changes/zaaktype-copy/tasks.md#T16`
