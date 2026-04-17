<!-- ⚠️ EXTENSION NOTICE (auto-inserted by fix_extension_artifacts.py)
     Parent capability: vth-workflow-templates (Vth Workflow Templates)
     This spec extends the existing `vth-workflow-templates` capability. Do NOT define new entities or build new CRUD — reuse what `vth-workflow-templates` already provides. Your job is to add configuration, seed data, or workflow templates on top of that capability.
-->

# Tasks: enforcement-lhs

## Deduplication Check

- [ ] **D01**: Search `openspec/specs/` and `lib/Service/` for any existing LHS matrix logic, enforcement action service, or handhavingsactie controller. Document findings: expected result is "no overlap found — `handhavingsactie` schema exists but no service or UI was previously built for it."

## Schema & Configuration

- [ ] **T01**: Verify `handhavingsactie` schema is present in `lib/Settings/procest_register.json`. If absent, add it using the field definitions from ADR-000. Add 5 seed objects (see `design.md` Seed Data section) under `components.objects[]` using the `@self` envelope with `register: procest`, `schema: handhavingsactie`, and unique slugs. Add config key `enforcement_deadline_warning_days` (integer, default 7) to `SettingsService.php`.

## Backend: LHS Matrix Service

- [ ] **T02**: Create `lib/Service/LhsMatrixService.php`. Implement the 4×4 LHS matrix as a static PHP array keyed by `"{ernst}_{gedrag}"`. Expose two public methods: `suggest(string $ernst, string $gedrag): string` (returns intervention slug) and `getMatrix(): array` (returns full matrix for client-side use). Valid ernst values: `gering`, `matig`, `ernstig`, `zeer_ernstig`. Valid gedrag values: `goedwillend`, `onachtzaam`, `calculerend`, `opzettelijk`. Throw `\InvalidArgumentException` for unknown values. Add `@spec openspec/changes/enforcement-lhs/tasks.md#T02` PHPDoc tag.

## Backend: Enforcement Action Service

- [ ] **T03**: Create `lib/Service/HandhavingsactieService.php`. Inject `ObjectService` and `LhsMatrixService`. Implement:
  - `suggest(string $ernst, string $gedrag): string` — delegates to `LhsMatrixService::suggest()`
  - `create(array $data, string $userId): array` — validates that if `interventie !== LhsMatrixService::suggest(ernst, gedrag)` then `overrideReason` must be non-empty (throw HTTP 422 otherwise); saves via `ObjectService::saveObject()`
  - `update(string $id, array $data, string $userId): array` — same override validation on update; saves via `ObjectService::saveObject()`
  - `delete(string $id): void` — delegates to `ObjectService::deleteObject()`
  - `getForCase(string $caseId): array` — queries OpenRegister for handhavingsacties linked to the case
  - `getActiveNearDeadline(int $withinDays): array` — queries for status `actief` + effectueringsDatum within `$withinDays` days, `notificationSentAt` null
  Add `@spec openspec/changes/enforcement-lhs/tasks.md#T03` PHPDoc.

## Backend: Controller

- [ ] **T04**: Create `lib/Controller/HandhavingsactieController.php`. Thin controller (<10 lines per method). Expose:
  - `GET /api/handhavingsacties` → `index()` — supports query params `case`, `ernst`, `status`, `type`, `_page`, `_limit`
  - `POST /api/handhavingsacties` → `create()` — delegates to `HandhavingsactieService::create()`
  - `GET /api/handhavingsacties/{id}` → `show()`
  - `PUT /api/handhavingsacties/{id}` → `update()`
  - `DELETE /api/handhavingsacties/{id}` → `destroy()`
  - `POST /api/handhavingsacties/suggest` → `suggest()` — accepts `{ernst, gedrag}` body, returns `{interventie}` — no object created
  Add `@spec openspec/changes/enforcement-lhs/tasks.md#T04` PHPDoc.

## Backend: Background Job

- [ ] **T05**: Create `lib/BackgroundJob/HandhavingsactieDeadlineJob.php` extending `\OCP\BackgroundJob\TimedJob` (daily interval). In `run()`: call `HandhavingsactieService::getActiveNearDeadline($warningDays)` where `$warningDays` comes from `IAppConfig::getValueInt('procest', 'enforcement_deadline_warning_days', 7)`. For each result: look up the case's `assignee`, send a Nextcloud notification via `\OCP\Notification\IManager` with the deadline message (see REQ-ENF-006 Scenario 6.1), then update the `handhavingsactie` object setting `notificationSentAt` to the current timestamp. Register the job in `lib/AppInfo/Application.php`. Add `@spec openspec/changes/enforcement-lhs/tasks.md#T05` PHPDoc.

## Routes

- [ ] **T06**: Add to `appinfo/routes.php`:
  ```php
  ['name' => 'handhavingsactie#suggest', 'url' => '/api/handhavingsacties/suggest', 'verb' => 'POST'],
  ['name' => 'handhavingsactie#index',   'url' => '/api/handhavingsacties',          'verb' => 'GET'],
  ['name' => 'handhavingsactie#create',  'url' => '/api/handhavingsacties',          'verb' => 'POST'],
  ['name' => 'handhavingsactie#show',    'url' => '/api/handhavingsacties/{id}',     'verb' => 'GET'],
  ['name' => 'handhavingsactie#update',  'url' => '/api/handhavingsacties/{id}',     'verb' => 'PUT'],
  ['name' => 'handhavingsactie#destroy', 'url' => '/api/handhavingsacties/{id}',     'verb' => 'DELETE'],
  ```
  The `suggest` route MUST be declared before `{id}` routes to prevent slug collision.

## Frontend: Pinia Store and API Service

- [ ] **T07**: Create `src/stores/handhavingsactie.js` using `createObjectStore('handhavingsacties')`. Export the store as `useHandhavingsactieStore`. Create `src/services/handhavingsactieApi.js` with functions: `listHandhavingsacties(params)`, `createHandhavingsactie(data)`, `getHandhavingsactie(id)`, `updateHandhavingsactie(id, data)`, `deleteHandhavingsactie(id)`, `suggestInterventie({ernst, gedrag})` — all wrapping `fetch` calls to the respective endpoints.

## Frontend: LHS Matrix Dialog

- [ ] **T08**: Create `src/views/handhaving/components/LhsMatrixDialog.vue`. Two-step dialog:
  - **Step 1**: ernst selector (4 radio/button options with Dutch labels) + gedrag selector (4 options). On selection change, call `suggestInterventie()` from the API service and display the result as a highlighted badge: "Aanbevolen interventie: [label]". "Volgende" button advances to step 2.
  - **Step 2**: Full enforcement action form rendered by `CnFormDialog` from the `handhavingsactie` schema. Pre-populate `ernst`, `gedrag`, `interventie` from step 1. If the user changes `interventie`, reveal and require the `overrideReason` textarea (min 20 chars, validated client-side; also validated server-side in T03). "Opslaan" submits via `createHandhavingsactie()`. On success emit `'saved'` and close.

## Frontend: Case Detail Section

- [ ] **T09**: Create `src/views/handhaving/components/HandhavingsactieSection.vue`. On mount, load handhavingsacties for the current case via `listHandhavingsacties({case: caseId})`. Render each as a `HandhavingsactieCard.vue` (T10). Show "Actie toevoegen" button that opens `LhsMatrixDialog`. On `'saved'` event from dialog, reload the list. If an action has a non-null `overrideReason`, display a `⚠ LHS-afwijking` badge. Integrate into `CaseDetail.vue` as a collapsible section.

## Frontend: Enforcement Action Card

- [ ] **T10**: Create `src/views/handhaving/components/HandhavingsactieCard.vue`. Displays: interventie badge (colour-coded by severity level — `last_onder_bestuursdwang` = orange, `strafrechtelijk_optreden` = red, others = grey), ernst/gedrag pills, begunstigingstermijn countdown (days remaining until effectueringsDatum), status badge, optional LHS-afwijking icon with tooltip showing overrideReason. Clicking the card opens the update form via `CnFormDialog`.

## Frontend: Enforcement Overview View

- [ ] **T11**: Create `src/views/handhaving/HandhavingView.vue`. Uses `useListView` composable for search/filter/pagination state. Renders:
  - `CnActionsBar` with search box, "Exporteren" button (opens `CnMassExportDialog`), and result count.
  - `CnFilterBar` with filter chips for: ernst (multi-select), status (multi-select), deadline (presets: deze week, deze maand, verlopen), LHS-afwijking (boolean toggle).
  - `CnDataTable` with columns: Zaaknummer, Ernst, Gedrag, Interventie, Status, Effectueringsdatum, LHS-afwijking. Rows link to the parent case detail.
  - `CnMassExportDialog` triggered by "Exporteren" button — no custom export logic needed.

## Frontend: Router and Navigation

- [ ] **T12**: Add route `{ path: '/handhaving', name: 'handhaving', component: HandhavingView }` to `src/router/index.js`. Add "Handhaving" nav item with a gavel/shield icon to `src/views/navigation/AppNavigation.vue`, positioned after "Toezicht" (or similar inspection nav item).

## Verification Tasks

- [ ] **V01**: `LhsMatrixService::suggest('matig', 'calculerend')` returns `'last_onder_dwangsom'`
- [ ] **V02**: `POST /api/handhavingsacties/suggest` with `{ernst: 'ernstig', gedrag: 'goedwillend'}` returns `{interventie: 'last_onder_dwangsom'}` with HTTP 200 and no object created
- [ ] **V03**: Creating a handhavingsactie via API with `interventie` differing from LHS suggestion and no `overrideReason` returns HTTP 422
- [ ] **V04**: Creating a handhavingsactie with valid `overrideReason` saves successfully with `overrideReason` persisted
- [ ] **V05**: `HandhavingsactieDeadlineJob` sends a Nextcloud notification for an active enforcement action with effectueringsDatum within 7 days and does not re-send if `notificationSentAt` is set
- [ ] **V06**: `HandhavingView.vue` lists all enforcement actions and the LHS-afwijking filter correctly shows only overridden actions
- [ ] **V07**: Workflow guard blocks case status advancement when no handhavingsactie is linked (REQ-ENF-007 Scenario 7.1)
- [ ] **V08**: CSV export from `HandhavingView.vue` produces a file with correct Dutch column headers and all visible rows
- [ ] **V09**: Seed data loads on install: 5 handhavingsactie objects appear in the enforcement overview on a fresh Procest instance
- [ ] **V10**: `LhsMatrixDialog` live preview updates the suggested interventie without a page reload when ernst or gedrag selection changes
