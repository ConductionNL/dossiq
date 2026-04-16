# Tasks: inspection-checklists

## Deduplication Check

- [ ] **D01**: Verify no existing `InspectieService`, `InspectieChecklistController`, or `InspectieRapportController` exist in `lib/` — expected: no overlap. Confirm existing `WorkflowEngineController`, `TasksController`, `FileService`, `NotificationService` will be reused for hold/approval/photo logic per the design. Document findings in PR description.

## Backend: Service

- [ ] **T01**: Create `lib/Service/InspectieService.php`
  - `calculateResult(array $items, array $checklistItems): string` — returns `conform`, `niet_conform`, or `deels_conform`. Required item with `nee` result → niet_conform. All required items pass → conform. Mixed → deels_conform.
  - `countFailedItems(array $items, array $checklistItems): int` — counts required items with failing results.
  - `createNewVersion(string $checklistId): array` — clones the checklist object, increments `version`, sets `status: draft`, archives the original.
  - `selectSampleItems(array $items, int|float $sampleSize): array` — randomly selects `sampleSize` items from those marked as sample-eligible; returns the merged array of required non-sample items + selected sample items.
  - `createApprovalTask(string $caseId, string $rapportId, string $inspectorName, string $checklistName, int $failedItems): void` — creates a `task` via `TasksController` with structured title and description; notifies the toezichthouder via `NotificationService`.
  - `createQualityHoldTask(string $caseId, string $rapportId): void` — creates a quality hold task blocking case advancement.
  - Inject: `ObjectService`, `TasksController`, `NotificationService`, `ILogger`.
  - Add `@spec openspec/changes/inspection-checklists/tasks.md#T01` PHPDoc tag on class and all public methods.

## Backend: Controllers

- [ ] **T02**: Create `lib/Controller/InspectieChecklistController.php`
  - `index(IRequest $request): JSONResponse` — list templates; filter by `caseType` and `status` query params.
  - `show(IRequest $request, string $id): JSONResponse` — get single template.
  - `create(IRequest $request): JSONResponse` — create new template with `status: draft`, `version: 1`.
  - `update(IRequest $request, string $id): JSONResponse` — if `status: active`, call `InspectieService::createNewVersion()` and return the new version; if `draft`, update in place.
  - `publish(IRequest $request, string $id): JSONResponse` — set `status: active`; return 409 if another active version exists for same name+caseType.
  - `archive(IRequest $request, string $id): JSONResponse` — set `status: archived`.
  - Extends `Controller`. Inject `InspectieService`, `ObjectService`, `ILogger`.
  - Add `@spec` PHPDoc tag on class and all methods.

- [ ] **T03**: Create `lib/Controller/InspectieRapportController.php`
  - `index(IRequest $request): JSONResponse` — list reports; filter by `case`, `checklist`, `result`.
  - `show(IRequest $request, string $id): JSONResponse` — get single report.
  - `create(IRequest $request): JSONResponse` — start a new report; validate that the referenced checklist has `status: active`.
  - `update(IRequest $request, string $id): JSONResponse` — update in-progress report items; reject if report has been submitted.
  - `submit(IRequest $request, string $id): JSONResponse` — call `InspectieService::calculateResult()`, set `result`, `failedItems`, `inspectionDate`; if `result` is `niet_conform` or `deels_conform`, call `InspectieService::createApprovalTask()`; return updated report.
  - Add `@spec` PHPDoc tag on class and all methods.

- [ ] **T04**: Add inspection routes to `appinfo/routes.php`
  ```php
  // InspectieChecklists
  ['name' => 'InspectieChecklist#index',   'url' => '/api/inspectie-checklists',          'verb' => 'GET'],
  ['name' => 'InspectieChecklist#create',  'url' => '/api/inspectie-checklists',          'verb' => 'POST'],
  ['name' => 'InspectieChecklist#show',    'url' => '/api/inspectie-checklists/{id}',     'verb' => 'GET'],
  ['name' => 'InspectieChecklist#update',  'url' => '/api/inspectie-checklists/{id}',     'verb' => 'PUT'],
  ['name' => 'InspectieChecklist#publish', 'url' => '/api/inspectie-checklists/{id}/publish', 'verb' => 'POST'],
  ['name' => 'InspectieChecklist#archive', 'url' => '/api/inspectie-checklists/{id}/archive', 'verb' => 'POST'],
  // InspectieRapporten
  ['name' => 'InspectieRapport#index',     'url' => '/api/inspectie-rapporten',           'verb' => 'GET'],
  ['name' => 'InspectieRapport#create',    'url' => '/api/inspectie-rapporten',           'verb' => 'POST'],
  ['name' => 'InspectieRapport#show',      'url' => '/api/inspectie-rapporten/{id}',      'verb' => 'GET'],
  ['name' => 'InspectieRapport#update',    'url' => '/api/inspectie-rapporten/{id}',      'verb' => 'PUT'],
  ['name' => 'InspectieRapport#submit',    'url' => '/api/inspectie-rapporten/{id}/submit', 'verb' => 'POST'],
  ```
  Place specific routes BEFORE any wildcard `{slug}` routes per ADR-003.

## Backend: Seed Data

- [ ] **T05**: Add seed data to `lib/Settings/procest_register.json`
  - Add 3 `inspectieChecklist` objects with slugs: `bouwtoezicht-fundering-v1`, `brandveiligheid-horeca-v1`, `milieu-bodem-steekproef-v1` (field values in design.md Seed Data section)
  - Add 3 `inspectieRapport` objects with slugs: `rapport-bouwtoezicht-2026-0042`, `rapport-brandveiligheid-2026-0089`, `rapport-bodem-2026-0103`
  - Use `@self` envelope with `register: procest`, `schema: inspectieChecklist` / `inspectieRapport`
  - Verify idempotency: re-importing with `force: false` must not create duplicates (matched by slug)

## Frontend: Pinia Stores

- [ ] **T06**: Create `src/store/modules/inspectieChecklist.js` using `createObjectStore('inspectieChecklist')` with the `search`, `selection`, and `lifecycle` plugins. Export as `useInspectieChecklistStore`.

- [ ] **T07**: Create `src/store/modules/inspectieRapport.js` using `createObjectStore('inspectieRapport')` with the `search`, `files`, and `selection` plugins. Export as `useInspectieRapportStore`.

## Frontend: API Service

- [ ] **T08**: Create `src/services/inspectieApi.js`
  - `fetchChecklists(filters)` — GET `/api/inspectie-checklists`
  - `fetchChecklist(id)` — GET `/api/inspectie-checklists/{id}`
  - `createChecklist(data)` — POST `/api/inspectie-checklists`
  - `updateChecklist(id, data)` — PUT `/api/inspectie-checklists/{id}`
  - `publishChecklist(id)` — POST `/api/inspectie-checklists/{id}/publish`
  - `archiveChecklist(id)` — POST `/api/inspectie-checklists/{id}/archive`
  - `fetchRapporten(filters)` — GET `/api/inspectie-rapporten`
  - `fetchRapport(id)` — GET `/api/inspectie-rapporten/{id}`
  - `createRapport(data)` — POST `/api/inspectie-rapporten`
  - `updateRapport(id, data)` — PUT `/api/inspectie-rapporten/{id}`
  - `submitRapport(id)` — POST `/api/inspectie-rapporten/{id}/submit`
  - All use `axios` from `@nextcloud/axios` with `generateUrl('/apps/procest/api/...')`.

## Frontend: Admin Settings Components

- [ ] **T09**: Create `src/views/settings/tabs/InspectieChecklistList.vue`
  - Props: none. Uses `useInspectieChecklistStore`.
  - Layout: `CnActionsBar` with "Nieuwe checklist" button + case type filter dropdown. `CnDataTable` listing checklists with columns: Name, Case type, Version, Status (badge: draft/active/archived). Row actions: Edit (→ InspectieChecklistDetail), Publish (POST publish), Archive (POST archive).
  - Status badge colors: draft = grey, active = green, archived = muted.
  - All strings via `t(appName, '...')`.

- [ ] **T10**: Create `src/views/settings/tabs/InspectieChecklistDetail.vue`
  - Props: `checklistId` (String). Fetches checklist on mount via `fetchChecklist(id)`.
  - Layout: header with name, caseType select, status badge. Item list with drag-reorder (Vue.Draggable). Per-item form: label input, type select (ja_nee_nvt/tekst/getal/foto/meerkeuze), required toggle, fotoRequired toggle, helpText input, for meerkeuze: tag-style options input.
  - Actions: "Opslaan" (PUT), "Publiceren" (POST publish — only shown in draft), "Archiveren" (POST archive — only shown in active).
  - If checklist is `active` and user edits, show warning banner: "Bewerken van een actieve checklist maakt een nieuwe versie aan."

## Frontend: Case Detail Components

- [ ] **T11**: Create `src/views/cases/components/InspectieSection.vue`
  - Props: `caseId` (String), `caseTypeId` (String). Fetches inspection reports for the case on mount.
  - Layout: Section header "Inspecties" with "Nieuwe inspectie" button. List of `inspectieRapport` records with columns: Checklist name, Inspector, Date, Type (volledig/steekproef), Result badge (conform/niet_conform/deels_conform). Click row → shows `InspectieRapportDetail` in sidebar or modal.
  - "Nieuwe inspectie" opens checklist selector: dropdown of active checklists for the case type, type toggle (Volledige inspectie / Steekproef), then opens `InspectieRapportForm`.

- [ ] **T12**: Create `src/views/cases/components/InspectieRapportForm.vue`
  - Props: `checklistId` (String), `caseId` (String), `samplingMode` (Boolean, default false). Creates a new `inspectieRapport` on mount.
  - Layout: step-through form — one item per screen with progress indicator. Per item: label + helpText, input component based on `type` (toggle for ja_nee_nvt, number input for getal, textarea for tekst, file upload for foto, NcSelect for meerkeuze), comment textarea, photo upload (NcButton "Foto toevoegen" → file picker). Navigation: "Vorige" / "Volgende" / "Afronden" (on last item).
  - "Locatie vastleggen" button on first screen: uses `navigator.geolocation.getCurrentPosition()` if available, falls back to manual address input.
  - "Afronden" calls `submitRapport(id)` and shows result dialog with result badge + next action.
  - All photos uploaded via `FileService` attachment on the rapport object.
  - Emits: `@submitted(rapport)`, `@cancelled`.

- [ ] **T13**: Create `src/views/cases/components/InspectieRapportDetail.vue`
  - Props: `rapportId` (String). Fetches rapport on mount.
  - Layout: header with result badge, inspector name, date, location, checklist name + version. Per-item result list: item label, result value (color-coded: ja/conform = green, nee/niet_conform = red, nvt = grey), comment, photo thumbnails (click to open full-size). Footer: remarks field. If `followUpRequired`: show "Opvolging vereist" warning banner.
  - For supervisors with open approval task: show "Goedkeuren" and "Terugsturen" buttons. "Terugsturen" requires a non-empty return comment.

## Frontend: Integration

- [ ] **T14**: Integrate `InspectieSection` into `src/views/cases/CaseDetail.vue`
  - Import `InspectieSection` and add it as a named section in the case detail view, passing `caseId` and `caseTypeId` props.
  - Section is visible when the case type has at least one active checklist (check via `useInspectieChecklistStore`).

- [ ] **T15**: Integrate `InspectieChecklistList` into `src/views/settings/AdminRoot.vue`
  - Import `InspectieChecklistList` and add as a tab in the admin settings navigation with label "Inspectiechecklists".
  - Tab visible to admin users only.

## Verification Tasks

- [ ] **V01**: Creating a checklist template in draft status is not selectable for new inspections
- [ ] **V02**: Publishing a checklist makes it selectable; editing an active checklist creates version 2 and archives version 1
- [ ] **V03**: Inspection form renders all item types correctly (ja_nee_nvt toggle, getal input, tekst textarea, foto upload, meerkeuze select)
- [ ] **V04**: Submitting a conform inspection sets `result: conform`, `failedItems: 0`, no approval task created
- [ ] **V05**: Submitting a niet_conform inspection sets `result: niet_conform`, creates approval task assigned to toezichthouder, notifies supervisor
- [ ] **V06**: Submitting a deels_conform inspection sets `result: deels_conform`, creates approval task with failed item labels in description
- [ ] **V07**: Statistical sampling round shows only non-sample required items + randomly selected sample subset
- [ ] **V08**: Quality hold task blocks case status transitions until supervisor marks task complete
- [ ] **V09**: Supervisor can approve (task complete) or return (task reassigned to inspector with comment)
- [ ] **V10**: Seed data loads correctly — 3 checklists and 3 rapport objects appear in dev environment after install/repair
