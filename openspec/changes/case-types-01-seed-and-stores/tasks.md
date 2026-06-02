# Tasks: Case Types — Member 01 (Seed Data + Stores + i18n)

Feature tier tags: `[MVP]` = must ship, `[TEST]` = quality gate.
Member 1 of 4 in the case-types chain. `kind: config`.

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
- **Spec ref**: REQ-CT-07 through REQ-CT-11 (store prerequisite)
- **Files**: `src/store/store.js`
- **Acceptance**: All 5 types queryable via `objectStore.getObjects({ caseType: uuid })` in browser console; no console errors on app load

---

## TASK-CT-10: Add seed data to procest_register.json `[MVP]`

- [ ] In `lib/Settings/procest_register.json`, under the mock data `components.objects[]` section, add:
  - 4 caseType objects (slugs: ct-omgevingsvergunning, ct-subsidieaanvraag, ct-klachtbehandeling, ct-bezwaarschrift)
  - 14 statusType objects (3–4 per case type) with correct `caseType` slug references
  - 12 resultType objects (3 per case type) with `archivalAction` and `archivalPeriod`
  - 13 roleType objects (3–4 per case type)
- [ ] All objects use `@self` envelope with `{ "register": "procest", "schema": "...", "slug": "..." }`
- [ ] slugs are unique, human-readable, and stable (used for idempotency on re-import)
- [ ] All caseType objects pass the member-02 `validatePublish()` check (≥1 statusType, ≥1 isFinal, validFrom set)
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

## TASK-CT-01-VERIFY: Seed + store smoke verification `[TEST]`

- [ ] Fresh install: run repair step → verify 4 case types appear with all sub-entities in admin UI
- [ ] Re-run repair step → verify no duplicates
- [ ] Browser console: confirm the 5 sub-entity stores resolve without error on app load
- **Spec ref**: ADR-008 smoke testing rules; REQ-CT-17
- **Acceptance**: Seed data is present and idempotent; stores load cleanly
