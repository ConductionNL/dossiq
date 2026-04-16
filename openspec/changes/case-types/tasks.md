# Tasks: Case Types — V1 Implementation

Feature tier tags: `[MVP]` = must ship, `[V1]` = value-add, `[TEST]` = quality gate

---

## Deduplication Check

- [ ] **TASK-CT-00: Verify no overlap with existing platform capabilities**
  - Search `openregister/lib/Service/` for: ObjectService, RegisterService, SchemaService, ConfigurationService
  - Search `openspec/specs/` for existing sub-entity CRUD specs
  - Search `src/store/` for existing objectStore registrations for resultType, roleType, propertyDefinition, documentType, decisionType
  - Document findings: confirm all 5 sub-entity schemas are pre-registered (or note gaps)
  - **Acceptance**: Written note in PR description confirming no duplicate stores, services, or controllers were created

---

## TASK-CT-01: Register sub-entity stores `[MVP]`

- [ ] In `src/store/store.js`, add `registerObjectType` calls for the 5 missing sub-entities
  (if not already present):
  ```js
  objectStore.registerObjectType('result-type', 'resultType', 'procest')
  objectStore.registerObjectType('role-type', 'roleType', 'procest')
  objectStore.registerObjectType('property-definition', 'propertyDefinition', 'procest')
  objectStore.registerObjectType('document-type', 'documentType', 'procest')
  objectStore.registerObjectType('decision-type', 'decisionType', 'procest')
  ```
- [ ] Verify type names are kebab-case (ADR-015)
- [ ] Verify no duplicate registrations exist across OBJECT_TYPES or ENTITY_STORES
- **Spec ref**: REQ-CT-07 through REQ-CT-11
- **Files**: `src/store/store.js`
- **Acceptance**: All 5 types queryable via `objectStore.getObjects({ caseType: uuid })` in browser console; no console errors on app load

---

## TASK-CT-02: Create ResultTypesTab.vue `[V1]`

- [ ] Create `src/views/settings/tabs/ResultTypesTab.vue`
- [ ] Add SPDX header: `<!-- SPDX-License-Identifier: EUPL-1.2 -->`
- [ ] Accepts prop: `caseTypeId` (string, required)
- [ ] On mount: fetch result types where `caseType = caseTypeId` using `result-type` objectStore
- [ ] Render `CnDataTable` with columns: name, archivalAction (badge), archivalPeriod (formatted via `durationHelpers.js`), archivalStatus
- [ ] Add button → `CnFormDialog` (schema-driven) with fields: name (required), description, archivalAction (select: blijvend_bewaren/vernietigen), archivalPeriod (ISO 8601 text), archivalStatus
- [ ] Row Edit action → `CnFormDialog` pre-filled
- [ ] Row Delete action → `CnDeleteDialog` with confirmation text
- [ ] Every `await store.action()` wrapped in `try/catch` with user-facing error feedback
- [ ] All user-visible strings via `this.t('procest', '...')` — no hardcoded Dutch strings
- [ ] Import components from `@conduction/nextcloud-vue` only (never `@nextcloud/vue`)
- [ ] All imported components listed in `components: {}`
- **Spec ref**: REQ-CT-07 (CT-07-01 through CT-07-05)
- **Files**: `src/views/settings/tabs/ResultTypesTab.vue`
- **Acceptance**: Admin can add/edit/delete result types for a case type; table refreshes after each action; archivalPeriod displays as human-readable text (e.g., "20 jaar")

---

## TASK-CT-03: Create RoleTypesTab.vue `[V1]`

- [ ] Create `src/views/settings/tabs/RoleTypesTab.vue`
- [ ] Add SPDX header: `<!-- SPDX-License-Identifier: EUPL-1.2 -->`
- [ ] Accepts prop: `caseTypeId` (string, required)
- [ ] On mount: fetch role types where `caseType = caseTypeId` using `role-type` objectStore
- [ ] Render `CnDataTable` with columns: name, description (truncated)
- [ ] Add button → `CnFormDialog` with fields: name (required), description
- [ ] Row Edit action → `CnFormDialog` pre-filled
- [ ] Row Delete action → `CnDeleteDialog`
- [ ] Every `await store.action()` wrapped in `try/catch` with user-facing error feedback
- [ ] All user-visible strings via `this.t('procest', '...')`
- [ ] Import from `@conduction/nextcloud-vue` only
- **Spec ref**: REQ-CT-08 (CT-08-01 through CT-08-05)
- **Files**: `src/views/settings/tabs/RoleTypesTab.vue`
- **Acceptance**: Admin can add/edit/delete role types; name is required and validated; table updates immediately

---

## TASK-CT-04: Create PropertiesTab.vue `[V1]`

- [ ] Create `src/views/settings/tabs/PropertiesTab.vue`
- [ ] Add SPDX header: `<!-- SPDX-License-Identifier: EUPL-1.2 -->`
- [ ] Accepts prop: `caseTypeId` (string, required)
- [ ] On mount: fetch property definitions where `caseType = caseTypeId` using `property-definition` objectStore
- [ ] Render `CnDataTable` with columns: name, propertyType (badge), isRequired (icon), defaultValue
- [ ] Add button → `CnFormDialog` with fields: name (required), definition, description, propertyType (select: text/number/date/datetime), isRequired (checkbox), defaultValue
- [ ] Row Edit action → `CnFormDialog` pre-filled
- [ ] Row Delete action → `CnDeleteDialog`
- [ ] Every `await store.action()` wrapped in `try/catch` with user-facing error feedback
- [ ] All user-visible strings via `this.t('procest', '...')`
- [ ] Import from `@conduction/nextcloud-vue` only
- **Spec ref**: REQ-CT-09 (CT-09-01 through CT-09-05)
- **Files**: `src/views/settings/tabs/PropertiesTab.vue`
- **Acceptance**: Admin can add/edit/delete property definitions; propertyType dropdown shows 4 options; isRequired checkbox works correctly

---

## TASK-CT-05: Create DocumentTypesTab.vue `[V1]`

- [ ] Create `src/views/settings/tabs/DocumentTypesTab.vue`
- [ ] Add SPDX header: `<!-- SPDX-License-Identifier: EUPL-1.2 -->`
- [ ] Accepts prop: `caseTypeId` (string, required)
- [ ] On mount: fetch document types where `caseType = caseTypeId` using `document-type` objectStore
- [ ] Render `CnDataTable` with columns: name, category, isRequired (icon), confidentiality
- [ ] Add button → `CnFormDialog` with fields: name (required), description, category, isRequired (checkbox), confidentiality (select), allowedMimeTypes (text/tags input), validFrom, validUntil
- [ ] Row Edit action → `CnFormDialog` pre-filled
- [ ] Row Delete action → `CnDeleteDialog` with note: "Existing uploaded files will not be deleted"
- [ ] Every `await store.action()` wrapped in `try/catch` with user-facing error feedback
- [ ] All user-visible strings via `this.t('procest', '...')`
- [ ] Import from `@conduction/nextcloud-vue` only
- **Spec ref**: REQ-CT-10 (CT-10-01 through CT-10-04)
- **Files**: `src/views/settings/tabs/DocumentTypesTab.vue`
- **Acceptance**: Admin can add/edit/delete document types; delete dialog explicitly states existing files are preserved

---

## TASK-CT-06: Create DecisionTypesTab.vue `[V1]`

- [ ] Create `src/views/settings/tabs/DecisionTypesTab.vue`
- [ ] Add SPDX header: `<!-- SPDX-License-Identifier: EUPL-1.2 -->`
- [ ] Accepts prop: `caseTypeId` (string, required)
- [ ] On mount: fetch decision types where `caseType = caseTypeId` using `decision-type` objectStore
- [ ] Render `CnDataTable` with columns: name, isDraft (badge), publicationRequired (icon), validFrom
- [ ] Add button → `CnFormDialog` with fields: name (required), description, isDraft (checkbox), publicationRequired (checkbox), validFrom, validUntil
- [ ] Row Edit action → `CnFormDialog` pre-filled
- [ ] Row Delete action → `CnDeleteDialog`
- [ ] Every `await store.action()` wrapped in `try/catch` with user-facing error feedback
- [ ] All user-visible strings via `this.t('procest', '...')`
- [ ] Import from `@conduction/nextcloud-vue` only
- **Spec ref**: REQ-CT-11 (CT-11-01 through CT-11-03)
- **Files**: `src/views/settings/tabs/DecisionTypesTab.vue`
- **Acceptance**: Admin can add/edit/delete decision types; isDraft and publicationRequired checkboxes work correctly

---

## TASK-CT-07: Integrate new tabs into CaseTypeDetail.vue `[V1]`

- [ ] Import and register all 5 new tab components in `CaseTypeDetail.vue`
- [ ] Add tab entries in `NcTabPanel`: Results, Roles, Properties, Docs, Decisions
  (after existing General and Statuses tabs)
- [ ] Pass `caseTypeId` prop to each new tab component
- [ ] Verify no `CnDetailCard`-in-`CnDetailCard` nesting (ADR-017 — self-contained components)
- [ ] All new imports from `@conduction/nextcloud-vue` only
- [ ] All new components listed in `components: {}`
- **Spec ref**: REQ-CT-07 through REQ-CT-11; CT-15a through CT-15g
- **Files**: `src/views/settings/CaseTypeDetail.vue`
- **Acceptance**: All 7 tabs (General, Statuses, Results, Roles, Properties, Docs, Decisions) render without console errors; switching tabs fetches the correct sub-entities

---

## TASK-CT-08: Backend publish validation `[MVP]`

- [ ] In `lib/Service/ZgwZtcRulesService.php`, add `validatePublish(string $register, string $caseTypeId): array` method
  - Load statusType objects: `$this->objectService->findObjects($register, 'statusType', ['caseType' => $caseTypeId])`
  - If count === 0 → append "At least one status type must be defined before publishing"
  - If none has `isFinal = true` → append "At least one status type must be marked as final"
  - Load caseType object; if `validFrom` is empty → append "'Valid from' date must be set before publishing"
  - Return array of error strings (empty = valid)
- [ ] Hook `validatePublish()` into the case type save path: when `isDraft` transitions `true → false`, call validation and return HTTP 422 with `{ "errors": [...] }` if non-empty
- [ ] `@spec openspec/changes/case-types/tasks.md#task-ct-08` PHPDoc on new method
- [ ] SPDX header present: `// SPDX-License-Identifier: EUPL-1.2`
- [ ] Use `$this->objectService->findObjects($register, $schema, $params)` — 3 positional args (ADR-015)
- [ ] NEVER return `$e->getMessage()` in JSONResponse — use static error strings
- **Spec ref**: REQ-CT-02b (CT-02b-01 through CT-02b-05)
- **Files**: `lib/Service/ZgwZtcRulesService.php`
- **Acceptance**: `curl -X PATCH .../api/case-types/{uuid} -d '{"isDraft":false}'` on a case type with no status types returns HTTP 422 with "At least one status type must be defined before publishing"

---

## TASK-CT-09: Active case deletion guard `[MVP]`

- [ ] In the case type deletion path (controller or service), add a pre-deletion check:
  - Count case objects where `caseType = uuid` AND status is non-final using `findObjects()`
  - If count > 0: return HTTP 409 with `{ "message": "Cannot delete case type '...': {n} active cases are using this type. Close or reassign all cases first." }`
  - Also count all cases (including final); if > 0 but only closed: return HTTP 200 with `{ "warning": "...", "requiresConfirmation": true }` and require `?confirm=true` query param to proceed
- [ ] `@spec openspec/changes/case-types/tasks.md#task-ct-09` PHPDoc on guard logic
- [ ] SPDX header present
- [ ] 3-arg `findObjects()` pattern (ADR-015)
- **Spec ref**: REQ-CT-01d (CT-01d-01 through CT-01d-03)
- **Files**: `lib/Service/ZgwZtcRulesService.php` or `lib/Controller/ZtcController.php`
- **Acceptance**: Attempting to delete a case type with active cases via API returns HTTP 409; deleting with only closed cases returns the confirmation warning; deleting with no cases succeeds

---

## TASK-CT-10: Add seed data to procest_register.json `[MVP]`

- [ ] In `lib/Settings/procest_register.json`, under the mock data `components.objects[]` section, add:
  - 4 caseType objects (slugs: ct-omgevingsvergunning, ct-subsidieaanvraag, ct-klachtbehandeling, ct-bezwaarschrift)
  - 14 statusType objects (3–4 per case type) with correct `caseType` slug references
  - 12 resultType objects (3 per case type) with `archivalAction` and `archivalPeriod`
  - 13 roleType objects (3–4 per case type)
- [ ] All objects use `@self` envelope with `{ "register": "procest", "schema": "...", "slug": "..." }`
- [ ] slugs are unique, human-readable, and stable (used for idempotency on re-import)
- [ ] All caseType objects pass `validatePublish()` check (≥1 statusType, ≥1 isFinal, validFrom set)
- [ ] Values follow Dutch conventions: Dutch field values, valid ISO 8601 durations, realistic descriptions
- [ ] Re-run `importFromApp()` on an existing install: verify no duplicates created (idempotency test)
- **Spec ref**: REQ-CT-17 (CT-17-01 through CT-17-03)
- **Files**: `lib/Settings/procest_register.json`
- **Acceptance**: Fresh install shows 4 case types in admin settings list; each has status types, result types, and role types visible in their respective tabs; re-running repair does not duplicate any objects

---

## TASK-CT-11: Translations `[MVP]`

- [ ] Add all new user-visible strings to `l10n/en.json` (key == value, English)
- [ ] Add Dutch translations for all new strings to `l10n/nl.json`
- [ ] Strings to add (at minimum):
  - Tab labels: "Results", "Roles", "Properties", "Docs", "Decisions"
  - Form field labels: "Archive action", "Retention period", "Generic role", "Property type",
    "Is required", "Default value", "Category", "Direction", "Allowed MIME types",
    "Publication required"
  - archivalAction options: "Retain permanently", "Destroy"
  - Error messages from backend validation (already in English keys)
- [ ] Verify zero gaps between en.json and nl.json key sets
- **Spec ref**: ADR-007-i18n
- **Files**: `l10n/en.json`, `l10n/nl.json`
- **Acceptance**: `diff <(jq -r 'keys[]' l10n/en.json | sort) <(jq -r 'keys[]' l10n/nl.json | sort)` returns empty (no key gaps)

---

## TASK-CT-12: Unit tests for backend validation `[TEST]`

- [ ] Create `tests/Unit/Service/ZgwZtcRulesServiceTest.php`
- [ ] Add SPDX header: `// SPDX-License-Identifier: EUPL-1.2`
- [ ] Test `validatePublish()` — at least 4 test methods:
  - `testValidatePublishFailsWithNoStatusTypes()`
  - `testValidatePublishFailsWithNoFinalStatus()`
  - `testValidatePublishFailsWithMissingValidFrom()`
  - `testValidatePublishSucceedsWithAllPrerequisites()`
- [ ] Test `validateDeletion()` — at least 2 test methods:
  - `testDeletionBlockedWhenActiveCasesExist()`
  - `testDeletionAllowedWhenNoCasesExist()`
- [ ] All tests pass under `composer check:strict`
- [ ] `@spec openspec/changes/case-types/tasks.md#task-ct-12` in test file header
- **Spec ref**: ADR-008-testing; REQ-CT-02b; REQ-CT-01d
- **Files**: `tests/Unit/Service/ZgwZtcRulesServiceTest.php`
- **Acceptance**: `composer test` passes; ≥6 test methods; coverage includes both happy paths and error paths

---

## TASK-CT-13: Smoke test verification `[TEST]`

Before opening PR, verify each new endpoint and UI path actually works (per ADR-008):

- [ ] `curl -X PATCH .../api/case-types/{uuid} -d '{"isDraft":false}'` on a type with no statuses → returns HTTP 422
- [ ] `curl -X PATCH .../api/case-types/{uuid} -d '{"isDraft":false}'` on a fully configured type → returns HTTP 200
- [ ] `curl -X DELETE .../api/case-types/{uuid}` on a type with active cases → returns HTTP 409
- [ ] `curl -X DELETE .../api/case-types/{uuid}` on a type with no cases → returns HTTP 204 or 200
- [ ] Browser: open CaseTypeDetail for "Omgevingsvergunning" → verify all 7 tabs render
- [ ] Browser: Results tab → add "Vergunning verleend" (retain, P20Y) → verify row appears
- [ ] Browser: Roles tab → add "Aanvrager" → verify row appears; delete it → verify removed
- [ ] Browser: Properties tab → add "Kadastraal perceelnummer" (text, required) → verify row appears
- [ ] Browser: Docs tab → add "Bouwtekening" (required, application/pdf) → verify row appears
- [ ] Browser: Decisions tab → add "Vergunningsbesluit" (publicationRequired: true) → verify row appears
- [ ] Fresh install: run repair step → verify 4 case types appear with all sub-entities in admin UI
- [ ] Re-run repair step → verify no duplicates
- **Spec ref**: ADR-008 smoke testing rules
- **Acceptance**: All curl commands return expected status codes; all browser actions complete without console errors; seed data is present and idempotent
