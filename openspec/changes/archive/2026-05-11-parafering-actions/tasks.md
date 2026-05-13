# Tasks: parafering-actions

## Deduplication Check

- [ ] **D01**: Search `lib/Service/` and `lib/Controller/` for any existing `parafeeractie` handling. Check `src/store/store.js` for an existing `parafeer-actie` object store registration (may have been added by parafeerroute-engine; if present, do NOT re-register — document finding). Verify `ParafeerRouteService::completeStep` signature before calling it from `ParafeerActieService`. Verify `ObjectService::saveObject` uses 3 positional args (register, schema, data). Document findings here (expected: `parafeeractie` schema exists in ADR-000 and `procest_register.json` but no service, controller, or UI records or retrieves actions yet).

---

## Seed Data

- [ ] **T01**: Add 5 Dutch `parafeeractie` seed objects to `lib/Settings/procest_register.json` under `components.objects[]` as defined in `design.md`. Slugs: `parafeeractie-stap1-advies-0042`, `parafeeractie-stap2-parafering-0042`, `parafeeractie-stap3-accordering-0042`, `parafeeractie-stap2-teruggestuurd-0055`, `parafeeractie-stap1-advies-delegate-0071`. Use the `@self` envelope: `{ "@self": { "register": "procest", "schema": "parafeeractie", "slug": "..." }, ...properties }`. Verify re-import creates no duplicates.

---

## Backend: Service

- [ ] **T02**: Create `lib/Service/ParafeerActieService.php`. Methods:

  - `recordAction(string $voorstelId, array $data, IUser $currentUser): array`
    1. Fetch voorstel via `ObjectService::findObject($register, $schema, $voorstelId)`
    2. Decode `routeSnapshot` → get step at `voorstel.currentStep`
    3. Authorize: `$currentUser->getUID()` MUST equal step `actor`, OR `$data['onBehalfOf']` equals step `actor` AND mandate is valid. Throw `OCSForbiddenException` with static message `'Not authorized for this parafering step'` if neither applies. NEVER trust `$data` for identity — use `$currentUser` from `IUserSession`
    4. Validate `action` is allowed for the step type (`advies` → `advised`/`returned`; `parafering` → `parafered`/`returned`; `accordering` → `accorded`/`returned`). Throw `OCSBadRequestException` with static message `'Invalid action for this step type'` on mismatch
    5. Validate `comment` is non-empty when `action` = `returned`. Throw `OCSBadRequestException` with `'Return reason is required'` if empty
    6. Validate `advice` is non-empty when `action` = `advised`. Throw `OCSBadRequestException` with `'Advice text is required for advies steps'` if empty
    7. Build `parafeeractie` array: `voorstel`, `step`, `actor` = `$currentUser->getUID()`, `actorType` (`delegate` if `onBehalfOf` present, else `user`), `onBehalfOf`, `action`, `comment`, `advice`, `mandate`
    8. Save via `ObjectService::saveObject($register, $schema, $actieData)` (3 positional args)
    9. If `action` = `returned`: update voorstel `status` = `teruggestuurd`, `returnedFromStep` = `currentStep`; save voorstel; notify steller via `NotificatieService`; return
    10. Call `ParafeerRouteService::completeStep($voorstelId, $currentStep, $actieData)` to advance routing
    11. If step was final accordering AND voorstel has a `document` file ID: call `applyPdfSignature($voorstelId, $document, $currentUser, $step, $timestamp)`
    12. Catch all `\Throwable` (other than intentional exceptions above), log with `$this->logger->error()`, return static message `'Operation failed'`

  - `listActions(string $voorstelId): array`
    - Call `ObjectService::findObjects($register, $schema, ['voorstel' => $voorstelId])` (3 positional args)
    - Return array of parafeeractie objects sorted by `createdAt` ascending

  - `applyPdfSignature(string $voorstelId, string $fileId, IUser $actor, int $step, string $timestamp): void`
    - Fetch file content via `FileService`; append a plaintext signature block comment to the PDF binary (or use a PDF annotation if a PDF library is available)
    - Annotation text: `"Geaccordeerd via Procest parafeerroute\nActeur: [actor UID]\nStap: [step]\nTijdstip: [timestamp ISO 8601]"`
    - Write back via `FileService`; log success with `$this->logger->info()`
    - If file not found or write fails: log with `$this->logger->warning()` and continue without throwing (non-blocking per REQ-PAA-005-002)

  All methods MUST carry `@spec openspec/changes/parafering-actions/tasks.md#T02` PHPDoc tag. File-level `@spec` in header docblock. `@author Conduction Development Team <info@conduction.nl>`, `@license EUPL-1.2`.

---

## Backend: Controller

- [ ] **T03**: Create `lib/Controller/ParafeerActieController.php`. Endpoints:

  - `POST /api/parafeer-actie` — `#[NoAdminRequired]`: read request body, call `ParafeerActieService::recordAction($voorstelId, $data, $this->userSession->getUser())`, return 201 JSON on success; on `OCSForbiddenException` return 403 with `{"message": "Not authorized for this parafering step"}`; on `OCSBadRequestException` return 400 with `{"message": $e->getMessage()}`; on all other exceptions log + return 500 with `{"message": "Operation failed"}`
  - `GET /api/parafeer-actie` — `#[NoAdminRequired]`: read `voorstel` query param (required; return 400 if missing), call `ParafeerActieService::listActions($voorstelId)`, return 200 JSON array

  NEVER return `$e->getMessage()` for unexpected exceptions. Inject `IUserSession` via constructor. File carries `@spec`, `@author`, `@license` PHPDoc tags.

---

## Routes

- [ ] **T04**: Add routes to `appinfo/routes.php`:
  ```php
  ['name' => 'parafeer_actie#create', 'url' => '/api/parafeer-actie',      'verb' => 'POST'],
  ['name' => 'parafeer_actie#index',  'url' => '/api/parafeer-actie',      'verb' => 'GET'],
  ```
  Place BEFORE any existing wildcard `{slug}` routes.

---

## Frontend: Store

- [ ] **T05**: Check `src/store/store.js` for existing `parafeer-actie` registration (deduplication check D01 outcome). If NOT already registered: add `createObjectStore('parafeer-actie')` with no plugins (read-only use). If already registered: document finding and skip. Type name MUST be kebab-case and registered exactly ONCE.

---

## Frontend: API Service

- [ ] **T06**: Create `src/services/parafeerActieApi.js`. Functions:
  - `recordAction(data)` — `POST /api/parafeer-actie` with `{ voorstel, action, comment, advice, onBehalfOf, mandate }` via `axios` from `@nextcloud/axios`. `try/finally` loading state. NEVER raw `fetch()`.
  - `listActions(voorstelId)` — `GET /api/parafeer-actie?voorstel={voorstelId}` via `axios`.
  First line: `// SPDX-License-Identifier: EUPL-1.2`. ALL strings via `t(appName, '...')` where applicable.

---

## Frontend: Action Dialog

- [ ] **T07**: Create `src/views/cases/components/ParafeerActieDialog.vue`. Requirements:
  - First line: `<!-- SPDX-License-Identifier: EUPL-1.2 -->`
  - Import ONLY from `@conduction/nextcloud-vue` (NEVER `@nextcloud/vue` directly)
  - Props: `voorstelId` (string), `step` (object — the current parafeerstap from `routeSnapshot`), `open` (boolean)
  - Emits: `action-recorded`, `update:open`
  - Step-type-aware conditional rendering:
    - `advies` step: show NcTextareaField for `advice` (required) + optional comment NcTextareaField
    - `parafering` step: show optional comment NcTextareaField + "Paraferen" primary button + "Terugsturen" secondary button
    - `accordering` step: show optional comment NcTextareaField + "Accorderen" primary button + "Terugsturen" secondary button
  - "Terugsturen" always shows a mandatory reason textarea (NcTextareaField) inline (do NOT navigate to a separate dialog — toggle visibility in the same dialog)
  - Embed `DelegateSelectorField` below actor info; hidden when `mandates` store is empty
  - On submit: call `parafeerActieApi.recordAction(data)` in `try/catch`; on success emit `action-recorded`; on error show error via `NcDialog` (NEVER `window.confirm()` or `window.alert()`)
  - Validate required fields before enabling submit: advice non-empty for `advies` steps; reason non-empty for `returned` action
  - ALL user-visible strings via `this.t(appName, '...')` with English keys
  - EVERY component used in `<template>` MUST be imported AND listed in `components: {}`

---

## Frontend: Delegate Selector

- [ ] **T08**: Create `src/views/cases/components/DelegateSelectorField.vue`. Requirements:
  - First line: `<!-- SPDX-License-Identifier: EUPL-1.2 -->`
  - Props: `mandates` (array — list of configured mandates for the current user; each has `{ principalUid, principalDisplayName, mandateReference }`)
  - Emits: `update:onBehalfOf` (string UID), `update:mandate` (string reference)
  - If `mandates.length === 0`: render nothing (v-if, not v-show)
  - If `mandates.length > 0`: render NcSelectField with a "Namens" label and options: "Zichzelf (geen mandaat)" + one option per mandate entry
  - On selection of a mandate entry: emit both `update:onBehalfOf` and `update:mandate`
  - On selection of "Zichzelf": emit `update:onBehalfOf` with `null` and `update:mandate` with `null`
  - ALL strings via `this.t(appName, '...')`; EVERY used component imported and registered

---

## Frontend: Action History Timeline

- [ ] **T09**: Create `src/views/cases/components/ParafeerActieTimeline.vue`. Requirements:
  - First line: `<!-- SPDX-License-Identifier: EUPL-1.2 -->`
  - Import ONLY from `@conduction/nextcloud-vue`
  - Props: `voorstelId` (string)
  - On mount: call `parafeerActieApi.listActions(voorstelId)` in `try/catch`; store result in `acties`
  - Render using `CnTimelineStages` with one entry per `parafeeractie`:
    - Stage label: `"Stap [step] — [action label]"` (action labels in Dutch: `advised` → "Geadviseerd", `parafered` → "Geparafeerd", `accorded` → "Geaccordeerd", `returned` → "Teruggestuurd", `skipped` → "Overgeslagen")
    - Sub-label: actor display name + timestamp (formatted with Nextcloud locale via `formatValue`)
    - Body: advice or comment text (if present)
    - Delegate indicator: if `actorType` = `delegate`, show "Namens [onBehalfOf] (mandaat [mandate])"
  - Empty state: `CnEmptyState` with message `t(appName, 'No actions recorded yet')`
  - Every `await` in `try/catch` with user-facing error feedback
  - ALL strings via `this.t(appName, '...')`; EVERY used component imported and registered

---

## Frontend: Integration

- [ ] **T10**: Modify `src/views/cases/components/VoorstelDetail.vue`:
  - Import `ParafeerActieDialog`, `ParafeerActieTimeline`; register in `components: {}`
  - Show `ParafeerActieDialog` button ("Actie nemen") only when: `voorstel.status` = `in_parafering` AND `voorstel.currentStep` actor matches `currentUser.uid` (derive from settings store, NEVER from DOM)
  - Show "Terugsturen" secondary button alongside "Actie nemen" when same condition is met
  - Embed `<ParafeerActieTimeline :voorstelId="voorstel.id" />` in a `CnDetailCard` section titled "Paraferingshistorie" — always visible (not gated behind actor check)
  - After `action-recorded` event: reload voorstel object and timeline

---

## Translations

- [ ] **T11**: Add the following translation keys to `l10n/en.json` (English source) and `l10n/nl.json` (Dutch translation):

  | English key | Dutch translation |
  |-------------|-----------------|
  | `Take action` | `Actie nemen` |
  | `Return for revision` | `Terugsturen voor revisie` |
  | `Return reason is required` | `Reden is verplicht bij terugsturen` |
  | `Advice text is required for advies steps` | `Advies is verplicht voor adviesstappen` |
  | `Advise` | `Adviseren` |
  | `Approve (paraferen)` | `Paraferen` |
  | `Accord` | `Accorderen` |
  | `Return` | `Terugsturen` |
  | `On behalf of (mandaat)` | `Namens (mandaat)` |
  | `On behalf of` | `Namens` |
  | `Self (no mandate)` | `Zichzelf (geen mandaat)` |
  | `Parafering history` | `Paraferingshistorie` |
  | `No actions recorded yet` | `Nog geen acties vastgelegd` |
  | `Step {step} — {action}` | `Stap {step} — {action}` |
  | `Endorsed` | `Geparafeerd` |
  | `Advised` | `Geadviseerd` |
  | `Accorded` | `Geaccordeerd` |
  | `Returned` | `Teruggestuurd` |
  | `Skipped` | `Overgeslagen` |
  | `Not authorized for this parafering step` | `Niet bevoegd voor deze paraferstap` |
  | `Optional comment` | `Optioneel commentaar` |
  | `Advice` | `Advies` |
  | `Reason for returning` | `Reden voor terugsturen` |

---

## PHPUnit Tests

- [ ] **T12**: Create `tests/Unit/Service/ParafeerActieServiceTest.php` with ≥ 3 test methods:
  - `testRecordActionAuthorizedActor`: asserts `parafeeractie` saved and `completeStep` called when actor matches step
  - `testRecordActionUnauthorizedActorThrowsForbidden`: asserts `OCSForbiddenException` when `currentUser->getUID()` does not match step actor and no valid delegate
  - `testReturnedActionRequiresComment`: asserts `OCSBadRequestException` when `action` = `returned` and `comment` is empty
  - `testDelegateActionPopulatesOnBehalfOf`: asserts `parafeeractie` saved with `actorType` = `delegate` and `onBehalfOf` set
  Mock `ObjectService`, `ParafeerRouteService`, `NotificatieService`, and `FileService` — do NOT hit a real database.

---

## Pre-commit Verification

- [ ] **V01**: `grep -rL 'SPDX-License-Identifier' lib/Service/ParafeerActieService.php lib/Controller/ParafeerActieController.php src/views/cases/components/ParafeerActieDialog.vue src/views/cases/components/DelegateSelectorField.vue src/views/cases/components/ParafeerActieTimeline.vue src/services/parafeerActieApi.js` → zero results (all files have license header)
- [ ] **V02**: `grep -rn 'getMessage()' lib/Controller/ParafeerActieController.php` → zero results (no raw exception messages in API responses)
- [ ] **V03**: `grep -rn "from '@nextcloud/vue'" src/views/cases/components/ParafeerActieDialog.vue src/views/cases/components/DelegateSelectorField.vue src/views/cases/components/ParafeerActieTimeline.vue` → zero results (all imports via `@conduction/nextcloud-vue`)
- [ ] **V04**: `grep -rn 'fetch(' src/services/parafeerActieApi.js` → zero results (uses `@nextcloud/axios` only)
- [ ] **V05**: `grep -rn '@spec' lib/Service/ParafeerActieService.php lib/Controller/ParafeerActieController.php` → at least one `@spec openspec/changes/parafering-actions/tasks.md` tag per file
- [ ] **V06**: Curl `POST /api/parafeer-actie` as the step actor with valid body → 201; as a different user → 403; without `comment` when `action=returned` → 400; without `advice` when `action=advised` → 400
- [ ] **V07**: Curl `GET /api/parafeer-actie?voorstel={uuid}` → returns array of parafeeracties sorted ascending by `createdAt`; missing `voorstel` param → 400
- [ ] **V08**: Seed data import is idempotent — re-importing `procest_register.json` creates no duplicate `parafeeractie` objects (verify by slug match against slugs from T01)
- [ ] **V09**: Open voorstel detail as the current step actor → "Actie nemen" button visible; as a non-actor → button hidden
- [ ] **V10**: Submit advies action → `parafeeractie` created with `action=advised` and timeline shows new entry; submit returned action → voorstel status becomes `teruggestuurd` and steller notification sent
- [ ] **V11**: `ParafeerActieTimeline` renders empty state when no actions exist; renders all actions in chronological order when 3+ actions exist
