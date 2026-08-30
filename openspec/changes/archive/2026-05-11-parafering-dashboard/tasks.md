# Tasks: parafering-dashboard

## Deduplication Check

- [ ] **D01**: Search `lib/Service/` and `lib/Controller/` for any existing voorstel listing or reminder handling. Check `src/store/store.js` for existing `voorstel` object store registration (likely already registered by parafeerroute-engine — do NOT re-register). Check `src/router/router.js` for any existing `/voorstellen` route. Verify `ParafeerActieDialog` and `parafeerActieApi.js` exist (from parafering-actions) — these are reused, not recreated. Verify `NotificatieService` injection pattern used in other services. Document findings here.

---

## Seed Data

- [ ] **T01**: Add 4 Dutch `voorstel` seed objects to `lib/Settings/procest_register.json` under `components.objects[]` as defined in `design.md`. Slugs: `voorstel-collegeadvies-omgevingsvergunning-0101`, `voorstel-dt-advies-subsidieregeling-0055`, `voorstel-raadsvoorstel-begroting-0200`, `voorstel-collegeadvies-parkeerterrein-0142`. Use the `@self` envelope: `{ "@self": { "register": "procest", "schema": "voorstel", "slug": "..." }, ...properties }`. Verify re-import creates no duplicates.

---

## Backend: Service

- [ ] **T02**: Create `lib/Service/ParafeerHerinneringService.php`. Methods:

  - `sendReminder(string $voorstelId, IUser $currentUser): array`
    1. Fetch voorstel via `ObjectService::findObject($register, $schema, $voorstelId)`
    2. Validate `voorstel.status` = `in_parafering`; throw `OCSBadRequestException` with static message `'Voorstel is not in parafering'` if not
    3. Decode `routeSnapshot` → get step at `voorstel.currentStep - 1` (0-indexed)
    4. Derive actor UID from `step.actor`
    5. Send Nextcloud notification to actor via `NotificatieService`: `"Voorstel '{onderwerp}' wacht op uw parafering ({days} dagen)"` where `{days}` is computed from `voorstel.updatedAt`
    6. Log reminder: add a note to the voorstel via `ObjectService::saveObject` updating `voorstel.notes` or log via `$this->logger->info()` — use whichever note/audit approach the platform provides
    7. Return `['message' => 'Herinnering verstuurd', 'voorstelId' => $voorstelId, 'actor' => $actorUid]`
    8. Catch all `\Throwable`, log with `$this->logger->error()`, return static message `'Operation failed'`

  - `getDaysWaiting(array $voorstel): int`
    - Parse `voorstel.updatedAt` (ISO 8601) and compute integer days elapsed since that timestamp
    - Return 0 if `updatedAt` is null or cannot be parsed

  All methods MUST carry `@spec openspec/changes/parafering-dashboard/tasks.md#T02` PHPDoc tag. File-level `@spec` in header docblock. `@author Conduction Development Team <info@conduction.nl>`, `@license EUPL-1.2`.

---

## Backend: Controller

- [ ] **T03**: Create `lib/Controller/ParafeerHerinneringController.php`. Endpoints:

  - `POST /api/parafeer-herinnering` — `#[NoAdminRequired]`: read `voorstel` from request body (required; return 400 if missing), call `ParafeerHerinneringService::sendReminder($voorstelId, $this->userSession->getUser())`, return 201 JSON on success; on `OCSBadRequestException` return 400 with `{"message": $e->getMessage()}`; on all other exceptions log + return 500 with `{"message": "Operation failed"}`

  NEVER return `$e->getMessage()` for unexpected exceptions. Inject `IUserSession` via constructor. File carries `@spec`, `@author`, `@license` PHPDoc tags.

---

## Routes

- [ ] **T04**: Add route to `appinfo/routes.php`:
  ```php
  ['name' => 'parafeer_herinnering#create', 'url' => '/api/parafeer-herinnering', 'verb' => 'POST'],
  ```
  Place BEFORE any existing wildcard `{slug}` routes.

---

## Frontend: API Service

- [ ] **T05**: Create `src/services/herinneringApi.js`. Functions:
  - `sendReminder(voorstelId)` — `POST /api/parafeer-herinnering` with `{ voorstel: voorstelId }` via `axios` from `@nextcloud/axios`. `try/finally` loading state. NEVER raw `fetch()`.
  First line: `// SPDX-License-Identifier: EUPL-1.2`. ALL user-visible strings via `t(appName, '...')` where applicable.

---

## Frontend: Router

- [ ] **T06**: Add `/voorstellen` route to `src/router/router.js`:
  ```js
  {
    path: '/voorstellen',
    component: () => import('../views/voorstellen/VoorstellenList.vue'),
    name: 'voorstellen',
  }
  ```
  Verify the route does not conflict with any existing routes (deduplication check D01 outcome).

---

## Frontend: Sidebar Navigation

- [ ] **T07**: Add "Voorstellen" navigation item to the Procest sidebar navigation component (identified in deduplication check D01 — likely `src/navigation/Navigation.vue` or `src/App.vue`):
  - Label: `t(appName, 'Proposals')` with Dutch translation "Voorstellen"
  - Route: `/voorstellen`
  - Icon: use an appropriate icon from `@conduction/nextcloud-vue` (e.g., a document list icon)
  - Active state: highlight when current route name is `'voorstellen'`
  - Import ONLY from `@conduction/nextcloud-vue`

---

## Frontend: Secretariaat Dashboard

- [ ] **T08**: Create `src/views/voorstellen/VoorstellenList.vue`. Requirements:
  - First line: `<!-- SPDX-License-Identifier: EUPL-1.2 -->`
  - Import ONLY from `@conduction/nextcloud-vue`
  - On mount: fetch `GET /api/objects/voorstel?status=in_parafering` via `axios`; store in `voorstellen`; fetch `parafeeracties` per voorstel (batch or sequential) to derive `daysWaiting`
  - Sort state: maintain `sortBy` (field name) and `sortOrder` ('asc' | 'desc') in data; computed property `sortedVoorstellen` applies current sort
  - Columns: Onderwerp, Stap (step name), Actor (waiting actor display name), Wachttijd (days waiting), Voortgang (progress "stap X/Y"), Acties (reminder button column)
  - Overdue indicator: voorstel is overdue when `daysWaiting >= overdueThreshold` (hardcode threshold at 5 days for V1; extract to a named constant `OVERDUE_THRESHOLD_DAYS`)
  - Empty state: render `CnEmptyState` with message `t(appName, 'No active proposals')` when `voorstellen.length === 0`
  - Render `VoorstellenRow` for each voorstel; pass voorstel object and computed `daysWaiting` as props
  - ALL user-visible strings via `this.t(appName, '...')` with English keys
  - EVERY component used in `<template>` MUST be imported AND listed in `components: {}`

- [ ] **T09**: Create `src/views/voorstellen/components/VoorstellenRow.vue`. Requirements:
  - First line: `<!-- SPDX-License-Identifier: EUPL-1.2 -->`
  - Props: `voorstel` (object), `daysWaiting` (integer)
  - Computed: `currentStepLabel` from `routeSnapshot[currentStep-1].label` (parse `routeSnapshot` JSON string); `waitingActor` from `routeSnapshot[currentStep-1].actor`; `progress` as `"stap ${currentStep}/${totalSteps}"`; `isOverdue` as `daysWaiting >= OVERDUE_THRESHOLD_DAYS`
  - Render a table row (`<tr>`) with: onderwerp, currentStepLabel, waitingActor, daysWaiting (with overdue badge when `isOverdue`), progress, and `HerinneringButton` (shown only when `isOverdue`)
  - Guard against null/malformed `routeSnapshot` — fall back to empty string display, never crash
  - ALL strings via `this.t(appName, '...')`; EVERY used component imported and registered

- [ ] **T10**: Create `src/views/voorstellen/components/HerinneringButton.vue`. Requirements:
  - First line: `<!-- SPDX-License-Identifier: EUPL-1.2 -->`
  - Props: `voorstelId` (string)
  - On click: call `herinneringApi.sendReminder(voorstelId)` in `try/catch`; on success set `sent = true` for 3 seconds (show "Verstuurd" label), then reset; on error show error via `NcDialog`
  - Loading state: disable button and show spinner while request is in flight
  - ALL strings via `this.t(appName, '...')`; EVERY used component imported and registered

---

## Frontend: Personal Inbox

- [ ] **T11**: Create `src/views/MyWork/components/ParafeerInbox.vue`. Requirements:
  - First line: `<!-- SPDX-License-Identifier: EUPL-1.2 -->`
  - Import ONLY from `@conduction/nextcloud-vue`
  - On mount: fetch voorstellen where current user is active step actor. Use `GET /api/objects/voorstel?status=in_parafering` and filter client-side by `routeSnapshot[currentStep-1].actor === currentUser.uid` (derive `currentUser.uid` from settings store — NEVER from DOM)
  - For each voorstel: show onderwerp (links to voorstel detail), case reference (`voorstel.case`), steller display name, waiting-since date (formatted with Nextcloud locale)
  - Action buttons per item: "Paraferen" / "Adviseren" / "Accorderen" (label derived from current step type) and "Terugsturen" — both open `ParafeerActieDialog` passing the voorstel and current step as props
  - After `action-recorded` event from `ParafeerActieDialog`: re-fetch the inbox list
  - Empty state: `CnEmptyState` with message `t(appName, 'No proposals awaiting your action')`
  - Section heading: `t(appName, 'For endorsement')` (Dutch: "Ter parafering") — always rendered
  - EVERY `await` in `try/catch` with user-facing error feedback
  - ALL strings via `this.t(appName, '...')`; EVERY used component imported and registered

---

## Frontend: MyWork Integration

- [ ] **T12**: Modify `src/views/MyWork/MyWork.vue`:
  - Import `ParafeerInbox`; register in `components: {}`
  - Embed `<ParafeerInbox />` as a section in the MyWork view (append after existing sections; do not replace any existing content)
  - First line of file MUST remain `<!-- SPDX-License-Identifier: EUPL-1.2 -->` — preserve existing header

---

## Translations

- [ ] **T13**: Add the following translation keys to `l10n/en.json` (English source) and `l10n/nl.json` (Dutch translation):

  | English key | Dutch translation |
  |-------------|-----------------|
  | `Proposals` | `Voorstellen` |
  | `No active proposals` | `Geen actieve voorstellen` |
  | `For endorsement` | `Ter parafering` |
  | `No proposals awaiting your action` | `Geen voorstellen ter parafering` |
  | `Current step` | `Huidige stap` |
  | `Waiting actor` | `Wachtende actor` |
  | `Days waiting` | `Wachttijd (dagen)` |
  | `Progress` | `Voortgang` |
  | `Send reminder` | `Herinnering sturen` |
  | `Reminder sent` | `Verstuurd` |
  | `Overdue` | `Achterstallig` |
  | `step {step} of {total}` | `stap {step} van {total}` |
  | `Waiting since` | `Wacht sinds` |
  | `Proposal is not in parafering` | `Voorstel bevindt zich niet in parafering` |
  | `Operation failed` | `Actie mislukt` |

---

## PHPUnit Tests

- [ ] **T14**: Create `tests/Unit/Service/ParafeerHerinneringServiceTest.php` with ≥ 3 test methods:
  - `testSendReminderSendsNotificationToCurrentStepActor`: asserts `NotificatieService::sendNotification` called with correct actor UID and message when voorstel is `in_parafering`
  - `testSendReminderThrowsBadRequestWhenNotInParafering`: asserts `OCSBadRequestException` when voorstel status is not `in_parafering`
  - `testSendReminderThrowsBadRequestWhenVoorstelIdMissing`: asserts `OCSBadRequestException` when `voorstelId` is null or empty
  - `testGetDaysWaitingReturnsCorrectDays`: asserts correct integer returned for a known `updatedAt` timestamp
  Mock `ObjectService` and `NotificatieService` — do NOT hit a real database.

---

## Pre-commit Verification

- [ ] **V01**: `grep -rL 'SPDX-License-Identifier' lib/Service/ParafeerHerinneringService.php lib/Controller/ParafeerHerinneringController.php src/views/voorstellen/VoorstellenList.vue src/views/voorstellen/components/VoorstellenRow.vue src/views/voorstellen/components/HerinneringButton.vue src/views/MyWork/components/ParafeerInbox.vue src/services/herinneringApi.js` → zero results (all files have license header)
- [ ] **V02**: `grep -rn 'getMessage()' lib/Controller/ParafeerHerinneringController.php` → zero results (no raw exception messages in API responses)
- [ ] **V03**: `grep -rn "from '@nextcloud/vue'" src/views/voorstellen/VoorstellenList.vue src/views/voorstellen/components/VoorstellenRow.vue src/views/voorstellen/components/HerinneringButton.vue src/views/MyWork/components/ParafeerInbox.vue` → zero results (all imports via `@conduction/nextcloud-vue`)
- [ ] **V04**: `grep -rn 'fetch(' src/services/herinneringApi.js` → zero results (uses `@nextcloud/axios` only)
- [ ] **V05**: `grep -rn '@spec' lib/Service/ParafeerHerinneringService.php lib/Controller/ParafeerHerinneringController.php` → at least one `@spec openspec/changes/parafering-dashboard/tasks.md` tag per file
- [ ] **V06**: Curl `POST /api/parafeer-herinnering` with a valid `in_parafering` voorstel ID → 201 with `{"message": "Herinnering verstuurd", ...}`; with a `geaccordeerd` voorstel ID → 400 with `{"message": "Voorstel is not in parafering"}`; without `voorstel` body field → 400
- [ ] **V07**: Open `/voorstellen` as secretariaat with seed voorstellen present → 4 rows rendered; click column header → list re-orders; overdue row (daysWaiting ≥ 5) shows warning indicator and "Herinnering sturen" button
- [ ] **V08**: Open `/voorstellen` with zero `in_parafering` voorstellen → "Geen actieve voorstellen" empty state rendered
- [ ] **V09**: Open MyWork view as `wethouder.van.dam` with seed voorstellen → "Ter parafering" section shows matching voorstellen; click action button → `ParafeerActieDialog` opens; no pending voorstellen → "Geen voorstellen ter parafering" shown
- [ ] **V10**: Click "Voorstellen" in sidebar → navigates to `/voorstellen`; sidebar item is highlighted as active; navigating away removes active highlight
- [ ] **V11**: Seed data import is idempotent — re-importing `procest_register.json` creates no duplicate `voorstel` objects (verify by slug match against slugs from T01)
- [ ] **V12**: `ParafeerInbox.vue` renders empty state when no voorstellen match the current user; renders all matching voorstellen when 3+ exist; after recording an action the inbox list refreshes
