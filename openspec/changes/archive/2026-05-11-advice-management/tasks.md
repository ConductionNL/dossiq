# Tasks: advice-management

## Deduplication Check

- [ ] **D01**: Search `openspec/specs/` and `lib/Service/` for any existing advice or `adviesAanvraag` handling. Verify `ObjectService`, `NotificatieService`, and `TasksController` signatures match the 3-arg API before implementing `AdviceService`. Document findings here (expected: no overlap — `adviesAanvraag` schema exists in ADR-000 but no service or UI has been built for it).

---

## Schema & Configuration

- [ ] **T01**: Add `adviesAanvraag` schema to `lib/Settings/procest_register.json`. Schema fields: `case` (string, required), `adviseur` (string, required), `type` (string enum: intern/extern, required), `onderwerp` (string), `deadline` (string, ISO date), `status` (string enum: aangevraagd/ontvangen/verlopen), `adviesDocument` (string), `requestedAt` (string, ISO datetime), `receivedAt` (string, ISO datetime), `questions` (string). Add 5 Dutch seed objects (slugs: `advies-welstand-2026-0042`, `advies-veiligheidsregio-2026-0038`, `advies-rud-2026-0031`, `advies-juridisch-2026-0055`, `advies-brandweer-2026-0047`) as defined in `design.md`.

- [ ] **T02**: Add config keys to `lib/Service/SettingsService.php`: `advice_schema` (slug of adviesAanvraag schema), `advice_reminder_days` (integer, default 3). Load in `initializeStores()`.

---

## Backend: Service

- [ ] **T03**: Create `lib/Service/AdviceService.php`. Methods:
  - `createAdvice(string $caseId, array $data): array` — calls `ObjectService::saveObject()` with register/schema/object (3 args), then creates task via TasksController ("Advies uitbrengen voor [identifier]"), appends activity entry to case
  - `receiveAdvice(string $adviceId, string $fileId): array` — transitions status to `ontvangen`, sets `receivedAt`, stores `adviesDocument`, sends Nextcloud notification to behandelaar
  - `sendReminder(string $adviceId): void` — sends Nextcloud notification to adviseur via NotificatieService
  - `getAdviceForCase(string $caseId): array` — calls `ObjectService::findObjects()` filtered by `case` field
  - `checkGuard(string $caseId): array` — returns list of pending `adviesAanvraag` records with status `aangevraagd`
  All mutations MUST derive user identity from `IUserSession`, NEVER from frontend-sent data. Catch all `\Throwable`, log real error, return static error message to API.

---

## Backend: Controller

- [ ] **T04**: Create `lib/Controller/AdviceController.php`. Endpoints:
  - `GET /api/advice` — list by `case` query param (calls `AdviceService::getAdviceForCase`)
  - `POST /api/advice` — create (calls `AdviceService::createAdvice`); requires `IGroupManager` or case assignment check
  - `GET /api/advice/{id}` — get single (calls `ObjectService::findObject` 3-arg)
  - `PUT /api/advice/{id}` — update / receive advice (calls `AdviceService::receiveAdvice`)
  - `DELETE /api/advice/{id}` — delete (calls `ObjectService::deleteObject`)
  - `POST /api/advice/{id}/remind` — manual reminder (calls `AdviceService::sendReminder`)
  NEVER return `$e->getMessage()` in JSONResponse. Log exception with `$this->logger->error()`.

---

## Backend: Background Job

- [ ] **T05**: Create `lib/BackgroundJob/AdviceDeadlineJob.php` (extends `TimedJob`, interval 24h). Logic:
  1. Query all `adviesAanvraag` with `status` = `aangevraagd`
  2. For each with `deadline` < today: transition to `verlopen`, create task for behandelaar ("Advies verlopen: beoordeel of vergunningprocedure kan doorgaan zonder dit advies"), send escalation notification to behandelaar and teamleider
  3. For each with `deadline` = today + 3 days: send reminder notification to behandelaar ("Herinnering: advies van [adviseur] verwacht op [deadline]")
  Register in `appinfo/info.xml` under `<background-jobs>`.

---

## Routes

- [ ] **T06**: Add advice routes to `appinfo/routes.php`:
  ```php
  ['name' => 'advice#index',   'url' => '/api/advice',           'verb' => 'GET'],
  ['name' => 'advice#create',  'url' => '/api/advice',           'verb' => 'POST'],
  ['name' => 'advice#show',    'url' => '/api/advice/{id}',      'verb' => 'GET'],
  ['name' => 'advice#update',  'url' => '/api/advice/{id}',      'verb' => 'PUT'],
  ['name' => 'advice#destroy', 'url' => '/api/advice/{id}',      'verb' => 'DELETE'],
  ['name' => 'advice#remind',  'url' => '/api/advice/{id}/remind', 'verb' => 'POST'],
  ```

---

## Frontend: Store

- [ ] **T07**: Register `advies-aanvraag` entity type in `src/store/store.js` via `createObjectStore('advies-aanvraag')` with `relationsPlugin` and `filesPlugin`. Type name MUST be kebab-case. Register ONCE — do NOT duplicate in OBJECT_TYPES and ENTITY_STORES.

---

## Frontend: API Service

- [ ] **T08**: Create `src/services/adviceApi.js`. Functions: `getAdviceForCase(caseId)`, `createAdvice(data)`, `getAdvice(id)`, `updateAdvice(id, data)`, `deleteAdvice(id)`, `sendReminder(id)`. Use `axios` from `@nextcloud/axios` for ALL calls (CSRF auto-attach). NEVER raw `fetch()`.

---

## Frontend: Advice Panel Component

- [ ] **T09**: Create `src/views/cases/components/AdviesPanel.vue`. Requirements:
  - Import ONLY from `@conduction/nextcloud-vue` (NOT `@nextcloud/vue`)
  - List all `adviesAanvraag` for the current case using `adviceApi.getAdviceForCase(caseId)`
  - Every `await` call MUST be in `try/catch` with user-facing error via `NcDialog` or toast
  - Render per-advice row: adviseur name, `CnStatusBadge` for type (intern=grey, extern=blue), `CnStatusBadge` for status (aangevraagd=info, ontvangen=success, verlopen=error), deadline date
  - Highlight overdue rows in red (status=aangevraagd AND deadline < today); show days overdue count
  - Quick actions via `CnRowActions`: "Herinnering sturen" (aangevraagd), "Bekijk advies" (ontvangen), "Markeer als ontvangen" (aangevraagd + document present)
  - Empty state: `CnEmptyState` with message `t(appName, 'Geen adviezen aangevraagd')`
  - "Advies aanvragen" button opens `AdviesAanvraagDialog`
  - ALL user-visible strings via `this.t(appName, '...')` — Dutch translations in `l10n/nl.json`
  - SPDX header: `<!-- SPDX-License-Identifier: EUPL-1.2 -->` as first line
  - EVERY component used in `<template>` MUST be imported AND listed in `components: {}`

---

## Frontend: Create Advice Dialog

- [ ] **T10**: Create `src/views/cases/components/AdviesAanvraagDialog.vue`. Requirements:
  - Import ONLY from `@conduction/nextcloud-vue`
  - Fields: `type` toggle (Intern/Extern), `adviseur` (NcUserPicker for intern, NcInputField for extern), `onderwerp` (NcInputField, required), `deadline` (NcDateTimePicker, default = today + 14 days), `questions` (NcTextareaField, optional)
  - Validate `adviseur` and `deadline` filled before enabling submit button
  - On submit: call `adviceApi.createAdvice(data)` in `try/catch`, emit `created` event, close dialog
  - NEVER `window.confirm()` or `window.alert()` — use `NcDialog` for confirmations
  - ALL strings via `this.t(appName, '...')`
  - SPDX header on line 1

---

## Frontend: Case Detail Integration

- [ ] **T11**: Modify `src/views/cases/CaseDetail.vue` to embed `AdviesPanel` component:
  - Import `AdviesPanel` from `./components/AdviesPanel.vue`
  - Register in `components: { AdviesPanel }`
  - Add `<AdviesPanel :case-id="caseId" />` in the case detail layout (after the existing task/role panels)
  - Pass `caseId` from route params

---

## Frontend: Workflow Guard Integration

- [ ] **T12**: Modify `src/views/workflow/WorkflowTransitionButton.vue`:
  - Before triggering a transition with a guard of type `adviesGuard`, call `adviceApi.getAdviceForCase(caseId)` and filter for status = `aangevraagd`
  - If any pending advice exists: disable the transition button and show tooltip listing pending advice: "[adviseur]: advies verwacht voor [deadline]"
  - Guard check MUST run on component mount AND after any advice panel update
  - Wrap in `try/catch` with user-facing error feedback

---

## Seed Data Generation

- [ ] **T13**: Verify the 5 seed `adviesAanvraag` objects defined in `design.md` are present in `lib/Settings/procest_register.json` under `components.objects[]` using the `@self` envelope. Confirm slugs are unique and idempotent. Test by running the import twice and verifying no duplicates are created.

---

## Pre-commit Verification

- [ ] **V01**: `grep -rL 'SPDX-License-Identifier' lib/Service/AdviceService.php lib/Controller/AdviceController.php lib/BackgroundJob/AdviceDeadlineJob.php src/views/cases/components/AdviesPanel.vue src/views/cases/components/AdviesAanvraagDialog.vue src/services/adviceApi.js` → zero results (all files have SPDX header)
- [ ] **V02**: `grep -rn 'findObject\|saveObject\|findObjects' lib/Service/AdviceService.php` → every call has 3 positional args
- [ ] **V03**: `grep -rn 'getMessage()' lib/Controller/AdviceController.php` → zero results (no raw exception messages in API responses)
- [ ] **V04**: `grep -rn "from '@nextcloud/vue'" src/` → zero results (all imports via `@conduction/nextcloud-vue`)
- [ ] **V05**: `grep -rn 'fetch(' src/services/adviceApi.js` → zero results (uses `@nextcloud/axios` only)
- [ ] **V06**: `grep -rn 'adviesAanvraag\|advies-aanvraag' src/store/store.js` → exactly ONE registration in kebab-case
- [ ] **V07**: Advice panel renders correctly with 0, 1, and 3+ advice requests (manual QA in dev environment)
- [ ] **V08**: Workflow transition blocked when pending advice exists — transition button disabled with tooltip
- [ ] **V09**: `AdviceDeadlineJob` transitions expired advice to `verlopen` and creates behandelaar task (test with mocked date)
- [ ] **V10**: Seed data import is idempotent — re-importing `procest_register.json` creates no duplicate `adviesAanvraag` objects (verify by slug match)
