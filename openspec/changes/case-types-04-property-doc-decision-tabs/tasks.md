# Tasks: Case Types — Member 04 (Property + Document + Decision Tabs)

Feature tier tags: `[V1]` = value-add, `[TEST]` = quality gate.
Member 4 of 4 (final) in the case-types chain. `kind: code`. depends_on: case-types-03-result-role-tabs.

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

## TASK-CT-07b: Add Property/Doc/Decision tabs into CaseTypeDetail.vue `[V1]`

- [ ] Import and register `PropertiesTab`, `DocumentTypesTab`, `DecisionTypesTab` in `CaseTypeDetail.vue`
- [ ] Add tab entries in `NcTabPanel`: Properties, Docs, Decisions (after the Results and Roles tabs from member 03)
- [ ] Pass `caseTypeId` prop to each new tab component
- [ ] Verify no `CnDetailCard`-in-`CnDetailCard` nesting (ADR-017 — self-contained components)
- [ ] All new imports from `@conduction/nextcloud-vue` only
- [ ] All new components listed in `components: {}`
- **Spec ref**: REQ-CT-09 through REQ-CT-11; CT-15a through CT-15g (Properties, Docs, Decisions)
- **Files**: `src/views/settings/CaseTypeDetail.vue`
- **Acceptance**: All 7 tabs (General, Statuses, Results, Roles, Properties, Docs, Decisions) render without console errors; switching tabs fetches the correct sub-entities

---

## TASK-CT-13: Smoke test verification `[TEST]`

Before opening PR, verify each new UI path actually works (per ADR-008):

- [ ] Browser: open CaseTypeDetail for "Omgevingsvergunning" → verify all 7 tabs render
- [ ] Browser: Properties tab → add "Kadastraal perceelnummer" (text, required) → verify row appears
- [ ] Browser: Docs tab → add "Bouwtekening" (required, application/pdf) → verify row appears
- [ ] Browser: Decisions tab → add "Vergunningsbesluit" (publicationRequired: true) → verify row appears
- **Spec ref**: ADR-008 smoke testing rules
- **Acceptance**: All browser actions complete without console errors across all seven tabs
