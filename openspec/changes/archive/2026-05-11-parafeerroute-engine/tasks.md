# Tasks: parafeerroute-engine

## Deduplication Check

- [ ] **D01**: Search `lib/Service/` and `lib/Controller/` for any existing parafeerroute or parafeeractie handling. Check `SettingsService.php` for existing `parafeerroute_schema` or `parafeeractie_schema` config keys. Verify `ObjectService`, `NotificatieService`, and `TasksController` signatures match the 3-arg API before implementing `ParafeerRouteService`. Document findings here (expected: `parafeerroute` and `parafeeractie` schemas are defined in ADR-000 but no service, controller, or UI for them exists yet).

---

## Schema & Configuration

- [ ] **T01**: Add `parafeerroute` schema to `lib/Settings/procest_register.json`. Fields: `name` (string, required), `caseType` (string), `voorstelType` (string, enum: dt_advies/collegeadvies/raadsvoorstel), `steps` (array, required — each item has: `order` integer, `type` string enum advies/parafering/accordering, `actor` string, `actorType` string enum user/group/role, `mandatory` boolean), `isDefault` (boolean), `description` (string). Add 4 Dutch seed routes (slugs: `route-collegeadvies-omgevingsvergunning`, `route-collegeadvies-bestemmingsplan`, `route-dt-advies-standaard`, `route-raadsvoorstel-groot-project`) as defined in `design.md`.

- [ ] **T02**: Add `parafeeractie` schema to `lib/Settings/procest_register.json` if not already present. Fields: `voorstel` (string, required), `step` (integer, required), `actor` (string, required), `actorType` (string, enum: user/delegate), `onBehalfOf` (string), `action` (string, required, enum: parafered/returned/advised/skipped/accorded), `comment` (string), `advice` (string), `mandate` (string). Add 3 Dutch seed parafeeracties (slugs: `parafeeractie-stap1-advies-2026-0042`, `parafeeractie-stap2-parafering-2026-0042`, `parafeeractie-stap2-geretourneerd-2026-0055`) as defined in `design.md`.

- [ ] **T03**: Add config keys to `lib/Service/SettingsService.php`: `parafeerroute_schema` (slug of parafeerroute schema), `parafeeractie_schema` (slug of parafeeractie schema). Load in `initializeStores()`.

---

## Backend: Service

- [ ] **T04**: Create `lib/Service/ParafeerRouteService.php`. Methods:
  - `createRoute(array $data): array` — calls `ObjectService::saveObject($register, $schema, $data)` (3 args)
  - `updateRoute(string $routeId, array $data): array` — fetches existing object, merges $data, calls `saveObject` (3 args)
  - `getRoute(string $routeId): array` — calls `ObjectService::findObject($register, $schema, $routeId)`
  - `listRoutes(array $filters = []): array` — calls `ObjectService::findObjects($register, $schema, $filters)`
  - `deleteRoute(string $routeId): void` — checks no voorstel with status `in_parafering` references this route; if clear, calls `ObjectService::deleteObject`; otherwise throws with message "Route is in gebruik door actieve voorstellen"
  - `startParafering(string $voorstelId): void` — fetches voorstel, reads linked parafeerroute steps, stores JSON-encoded steps as `routeSnapshot`, sets `currentStep` = 1, saves voorstel, calls `activateStep($voorstelId, 1)`
  - `activateStep(string $voorstelId, int $step): void` — reads step $step from `routeSnapshot`, creates task for actor via `TasksController`, sends notification via `NotificatieService`: "Voorstel '[onderwerp]' wacht op uw [type] (stap [N] van [total])"
  - `completeStep(string $voorstelId, int $step, array $actionData): void` — records `parafeeractie` via `ObjectService::saveObject`, advances `currentStep` to $step+1 (or marks voorstel `geaccordeerd` if $step was the last), saves voorstel, calls `activateStep` for next step or sends geaccordeerd notification to steller
  - `skipStep(string $voorstelId, int $step, string $reason): void` — reads step from `routeSnapshot`, checks `mandatory` = false (throws on true), records `parafeeractie` with action=skipped and comment=$reason, appends entry to voorstel `auditTrail`, advances `currentStep`
  - `addAdhocStep(string $voorstelId, int $afterStep, array $stepData): void` — decodes `routeSnapshot`, inserts new step after $afterStep, renumbers subsequent steps, re-encodes and saves to voorstel, appends entry to voorstel `auditTrail`
  All mutations MUST derive user identity from `IUserSession`, NEVER from frontend-sent data. Catch all `\Throwable`, log real error with `$this->logger->error()`, return static error message to API.

---

## Backend: Controller

- [ ] **T05**: Create `lib/Controller/ParafeerRouteController.php`. Endpoints:
  - `GET /api/parafeer-route` — list routes with optional `caseType` and `voorstelType` query params (calls `listRoutes`)
  - `POST /api/parafeer-route` — create route; requires admin group check via `IGroupManager` (calls `createRoute`)
  - `GET /api/parafeer-route/{id}` — get single route (calls `getRoute`)
  - `PUT /api/parafeer-route/{id}` — update route; requires admin group check (calls `updateRoute`)
  - `DELETE /api/parafeer-route/{id}` — delete route; requires admin group check (calls `deleteRoute`)
  - `POST /api/parafeer-route/voorstel/{voorstelId}/start` — start parafering (calls `startParafering`; requires case assignment or admin check)
  - `POST /api/parafeer-route/voorstel/{voorstelId}/complete-step` — complete current step (calls `completeStep`; requires actor is current step actor)
  - `POST /api/parafeer-route/voorstel/{voorstelId}/skip-step` — skip step (calls `skipStep`; requires manager role)
  - `POST /api/parafeer-route/voorstel/{voorstelId}/add-step` — add ad-hoc step (calls `addAdhocStep`; requires steller or manager)
  NEVER return `$e->getMessage()` in JSONResponse. Log exceptions with `$this->logger->error()`.

---

## Routes

- [ ] **T06**: Add parafeerroute routes to `appinfo/routes.php`:
  ```php
  ['name' => 'parafeer_route#index',        'url' => '/api/parafeer-route',                                      'verb' => 'GET'],
  ['name' => 'parafeer_route#create',       'url' => '/api/parafeer-route',                                      'verb' => 'POST'],
  ['name' => 'parafeer_route#show',         'url' => '/api/parafeer-route/{id}',                                 'verb' => 'GET'],
  ['name' => 'parafeer_route#update',       'url' => '/api/parafeer-route/{id}',                                 'verb' => 'PUT'],
  ['name' => 'parafeer_route#destroy',      'url' => '/api/parafeer-route/{id}',                                 'verb' => 'DELETE'],
  ['name' => 'parafeer_route#start',        'url' => '/api/parafeer-route/voorstel/{voorstelId}/start',          'verb' => 'POST'],
  ['name' => 'parafeer_route#completeStep', 'url' => '/api/parafeer-route/voorstel/{voorstelId}/complete-step',  'verb' => 'POST'],
  ['name' => 'parafeer_route#skipStep',     'url' => '/api/parafeer-route/voorstel/{voorstelId}/skip-step',      'verb' => 'POST'],
  ['name' => 'parafeer_route#addStep',      'url' => '/api/parafeer-route/voorstel/{voorstelId}/add-step',       'verb' => 'POST'],
  ```

---

## Frontend: Store

- [ ] **T07**: Register `parafeer-route` entity type in `src/store/store.js` via `createObjectStore('parafeer-route')` with `relationsPlugin`. Also register `parafeer-actie` via `createObjectStore('parafeer-actie')`. Type names MUST be kebab-case. Register each ONCE — do NOT duplicate in OBJECT_TYPES and ENTITY_STORES.

---

## Frontend: API Service

- [ ] **T08**: Create `src/services/parafeerRouteApi.js`. Functions: `listRoutes(filters)`, `createRoute(data)`, `getRoute(id)`, `updateRoute(id, data)`, `deleteRoute(id)`, `startParafering(voorstelId)`, `completeStep(voorstelId, data)`, `skipStep(voorstelId, data)`, `addStep(voorstelId, data)`. Use `axios` from `@nextcloud/axios` for ALL calls (CSRF auto-attach). NEVER raw `fetch()`.

---

## Frontend: Admin Routes Tab

- [ ] **T09**: Create `src/views/settings/components/ParafeerRoutesTab.vue`. Requirements:
  - Import ONLY from `@conduction/nextcloud-vue` (NOT `@nextcloud/vue`)
  - Load all parafeerroutes on mount using `parafeerRouteApi.listRoutes()`
  - Per row: name, voorstelType `CnStatusBadge`, caseType name (or "Alle zaaktypen"), step count, `isDefault` badge, edit and delete `CnRowActions`
  - "Nieuwe route" button opens `ParafeerRouteDialog` in create mode
  - Edit action opens `ParafeerRouteDialog` in edit mode with route data pre-filled
  - Delete: confirm via `CnDialog` (never `window.confirm()`), then call `parafeerRouteApi.deleteRoute(id)`; display error if route is in active use
  - Empty state: `CnEmptyState` with message `t(appName, 'Geen parafeerroutes geconfigureerd')`
  - Every `await` MUST be in `try/catch` with user-facing error via `NcDialog` or toast
  - SPDX header: `<!-- SPDX-License-Identifier: EUPL-1.2 -->` as first line
  - ALL user-visible strings via `this.t(appName, '...')` with Dutch translations in `l10n/nl.json`
  - EVERY component used in `<template>` MUST be imported AND listed in `components: {}`

- [ ] **T10**: Create `src/views/settings/components/ParafeerRouteDialog.vue`. Requirements:
  - Import ONLY from `@conduction/nextcloud-vue`
  - Fields: `name` (NcInputField, required), `voorstelType` (NcSelectField: dt_advies/collegeadvies/raadsvoorstel), `caseType` (optional NcSelectField populated from existing caseTypes), `isDefault` (NcCheckboxRadioSwitch), `description` (NcTextareaField, optional)
  - Embeds `ParafeerStapEditor` component for step management; receives `steps` array and handles `update:steps` emission
  - Validate `name` and at least one step present before enabling submit
  - On save: call `parafeerRouteApi.createRoute(data)` or `updateRoute(id, data)` in `try/catch`, emit `saved` event, close dialog
  - SPDX header on line 1; ALL strings via `this.t(appName, '...')`

- [ ] **T11**: Create `src/views/settings/components/ParafeerStapEditor.vue`. Requirements:
  - Receives `steps` array as prop (array of parafeerstap objects); emits `update:steps` on every change
  - Per step row: order number (display only, 1-based), type select (advies/parafering/accordering), actorType select (user/group/role), actor input — NcUserPicker for `user`, NcInputField for `group` or `role` — mandatory NcCheckboxRadioSwitch
  - "Stap toevoegen" button appends a new empty step with `order` = last + 1
  - "Stap verwijderen" button removes step and renumbers remaining steps from 1
  - Up (▲) and down (▼) buttons to reorder steps; renumber all orders after each swap
  - SPDX header on line 1; ALL strings via `this.t(appName, '...')`

---

## Frontend: Voorstel Override Components

- [ ] **T12**: Create `src/views/cases/components/SkipStepDialog.vue`. Requirements:
  - Import ONLY from `@conduction/nextcloud-vue`
  - Receives `voorstelId`, `step` (parafeerstap object), emits `skipped`
  - Display step details: order, type, actor, mandatory flag
  - If `step.mandatory` = true: show warning "Deze stap is verplicht en kan niet worden overgeslagen" and disable submit button
  - If `step.mandatory` = false: show reason textarea (NcTextareaField, required) and enable submit
  - On confirm: call `parafeerRouteApi.skipStep(voorstelId, {step: step.order, reason})` in `try/catch`, emit `skipped`, close
  - SPDX header on line 1; ALL strings via `this.t(appName, '...')`

- [ ] **T13**: Create `src/views/cases/components/AddStepDialog.vue`. Requirements:
  - Import ONLY from `@conduction/nextcloud-vue`
  - Receives `voorstelId`, `routeSnapshot` (array of current steps); emits `step-added`
  - Insertion point selector: NcSelectField listing "Na stap N — [actor]" for each existing step
  - Step fields: type select (advies/parafering/accordering), actorType select (user/group/role), actor input, mandatory checkbox
  - On confirm: call `parafeerRouteApi.addStep(voorstelId, {afterStep, stepData})` in `try/catch`, emit `step-added`, close
  - SPDX header on line 1; ALL strings via `this.t(appName, '...')`

---

## Frontend: Integration

- [ ] **T14**: Modify `src/views/settings/AdminSettings.vue` to embed `ParafeerRoutesTab`:
  - Import `ParafeerRoutesTab` from `./components/ParafeerRoutesTab.vue`
  - Register in `components: { ParafeerRoutesTab }`
  - Add "Parafeerroutes" tab item to the settings tab navigation (after existing tabs)
  - Add `<ParafeerRoutesTab />` in the corresponding tab panel

- [ ] **T15**: Modify `src/views/cases/components/VoorstelDetail.vue` to embed override controls:
  - Import `SkipStepDialog` and `AddStepDialog`
  - Show "Stap overslaan" and "Stap toevoegen" buttons only when: voorstel status = `in_parafering` AND current user has manager/secretariaat role
  - Pass `voorstelId` and current `routeSnapshot` as props to each dialog
  - Reload voorstel after `skipped` or `step-added` events to reflect updated route

---

## Seed Data Generation

- [ ] **T16**: Verify the 4 seed `parafeerroute` objects and 3 seed `parafeeractie` objects defined in `design.md` are present in `lib/Settings/procest_register.json` under `components.objects[]` using the `@self` envelope. Confirm slugs are unique and idempotent. Test by running the import twice and verifying no duplicates are created.

---

## Pre-commit Verification

- [ ] **V01**: `grep -rL 'SPDX-License-Identifier' lib/Service/ParafeerRouteService.php lib/Controller/ParafeerRouteController.php src/views/settings/components/ParafeerRoutesTab.vue src/views/settings/components/ParafeerRouteDialog.vue src/views/settings/components/ParafeerStapEditor.vue src/views/cases/components/SkipStepDialog.vue src/views/cases/components/AddStepDialog.vue src/services/parafeerRouteApi.js` → zero results (all files have SPDX header)
- [ ] **V02**: `grep -rn 'findObject\|saveObject\|findObjects' lib/Service/ParafeerRouteService.php` → every call has 3 positional args (register, schema, data/id)
- [ ] **V03**: `grep -rn 'getMessage()' lib/Controller/ParafeerRouteController.php` → zero results (no raw exception messages in API responses)
- [ ] **V04**: `grep -rn "from '@nextcloud/vue'" src/views/settings/components/ src/views/cases/components/SkipStepDialog.vue src/views/cases/components/AddStepDialog.vue` → zero results (all imports via `@conduction/nextcloud-vue`)
- [ ] **V05**: `grep -rn 'fetch(' src/services/parafeerRouteApi.js` → zero results (uses `@nextcloud/axios` only)
- [ ] **V06**: `grep -rn 'parafeer-route\|parafeer-actie' src/store/store.js` → exactly ONE registration per entity in kebab-case
- [ ] **V07**: Admin "Parafeerroutes" tab renders correctly with 0 routes (empty state) and 3+ routes (manual QA in dev environment)
- [ ] **V08**: Creating a 4-step route saves correctly to OpenRegister; editing the route template does NOT change the `routeSnapshot` on any existing voorstel that was already submitted (verify by checking voorstel object directly)
- [ ] **V09**: Skip step blocked for a step with `mandatory` = true — error message displayed, no parafeeractie created; skip succeeds for `mandatory` = false with reason — parafeeractie recorded, auditTrail entry added
- [ ] **V10**: Add ad-hoc step at position 2 on a 4-step voorstel results in a `routeSnapshot` with 5 steps and original steps 3–4 renumbered to 4–5; auditTrail entry added
- [ ] **V11**: Seed data import is idempotent — re-importing `procest_register.json` creates no duplicate `parafeerroute` or `parafeeractie` objects (verify by slug match)
