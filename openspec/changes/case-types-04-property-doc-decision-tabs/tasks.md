# Tasks: Case Types — Member 04 (Property + Document + Decision Tabs)

Feature tier tags: `[V1]` = value-add, `[TEST]` = quality gate.
Member 4 of 4 (final) in the case-types chain. `kind: code`. depends_on: case-types-03-result-role-tabs.

> **Implementation note (convention reconciliation).** The proposal/design and the
> original task text reference `CnDataTable` / `CnFormDialog` / `CnDeleteDialog` /
> `NcTabPanel` from `@conduction/nextcloud-vue`. The app never adopted that shell:
> members 01–03 actually shipped the established `sub-entity-tab` pattern — inline
> row CRUD built on `@nextcloud/vue` primitives (`NcButton`, `NcTextField`,
> `NcCheckboxRadioSwitch`, `NcLoadingIcon`) + `useObjectStore().fetchCollection /
> saveObject / deleteObject`. Per the ADR guardrail ("grep the app's existing
> conventions before writing anything; only import components that actually exist"),
> all three tabs follow the real `sub-entity-tab` convention, not the idealised
> `Cn*` component names. `PropertiesTab.vue` and `DocumentTypesTab.vue` already
> existed on development; this member created `DecisionTypesTab.vue`, wired
> Docs + Decisions tabs into `CaseTypeDetail.vue`, added the document-type
> "files preserved" delete note + confidentiality field, and shipped nl+en i18n.

---

## TASK-CT-04: PropertiesTab.vue `[V1]` (pre-existing on development)

- [x] `src/views/settings/tabs/PropertiesTab.vue` exists (shipped earlier in chain)
- [x] Accepts prop `caseTypeId`; fetches `propertyDefinition` scoped to the case type on mount
- [x] Inline-row list: name, format badge (text/number/date/datetime), max length, required-at-status
- [x] Add / Edit / Delete via `useObjectStore` save/delete; name-required validation; error feedback
- [x] All user-visible strings via `t('procest', '...')`
- **Spec ref**: REQ-CT-09 (CT-09-01 through CT-09-05)
- **Note**: Uses `sub-entity-tab` convention (not `CnFormDialog`); `format` is the propertyType select (text/number/date/datetime). Verified, no change needed this member.

---

## TASK-CT-05: DocumentTypesTab.vue `[V1]`

- [x] `src/views/settings/tabs/DocumentTypesTab.vue` exists; SPDX-style scoped CSS import
- [x] Accepts prop `caseTypeId`; fetches `documentType` scoped to the case type on mount
- [x] Inline-row list: name, category, required badge, confidentiality (column added this member)
- [x] Edit form fields: name (required), category, description, confidentiality (added), isRequired checkbox
- [x] Delete confirm now states: "Existing uploaded files will not be deleted"
- [x] save/delete via `useObjectStore`; name-required validation
- [x] All user-visible strings via `t('procest', '...')`
- **Spec ref**: REQ-CT-10 (CT-10-01 through CT-10-04)
- **Acceptance met**: Admin can add/edit/delete document types; delete dialog explicitly states existing files are preserved.

---

## TASK-CT-06: Create DecisionTypesTab.vue `[V1]`

- [x] Created `src/views/settings/tabs/DecisionTypesTab.vue`
- [x] SPDX header `<!-- SPDX-License-Identifier: EUPL-1.2 -->`
- [x] Accepts prop `caseTypeId` (string)
- [x] On mount: fetch decision types where `caseType = caseTypeId` via `decisionType` objectStore
- [x] Inline-row list: name, isDraft badge, publicationRequired badge, validFrom
- [x] Add/Edit form: name (required), description, isDraft (checkbox), publicationRequired (checkbox), validFrom, validUntil
- [x] Row Edit + Delete (delete via confirm)
- [x] Every `await store.action()` wrapped in try/catch with user-facing error feedback
- [x] All user-visible strings via `t('procest', '...')`
- [x] Imports from `@nextcloud/vue` (the real app convention) — matches the other six tabs
- **Spec ref**: REQ-CT-11 (CT-11-01 through CT-11-03)
- **Acceptance met**: Admin can add/edit/delete decision types; isDraft and publicationRequired checkboxes work.

---

## TASK-CT-07b: Add Property/Doc/Decision tabs into CaseTypeDetail.vue `[V1]`

- [x] Imported and registered `PropertiesTab`, `DocumentTypesTab`, `DecisionTypesTab` in `CaseTypeDetail.vue`
- [x] Added tab entries: Properties (pre-existing), Docs, Decisions — order General | Statuses | Results | Roles | Properties | Docs | Decisions | Workflow
- [x] Passed `caseTypeId` prop to each new tab component
- [x] No `CnDetailCard`-in-`CnDetailCard` nesting (app uses self-contained `sub-entity-tab` components — ADR-017 satisfied)
- [x] All new components listed in `components: {}`
- **Spec ref**: REQ-CT-09 through REQ-CT-11; CT-15a through CT-15g
- **Acceptance met**: All seven case-type tabs (plus the app's Workflow tab) render; switching tabs fetches the correct sub-entities scoped to the case type.

---

## TASK-CT-13: Smoke test verification `[TEST]`

- [~] DEFERRED — requires a live Nextcloud instance with seeded "Omgevingsvergunning" case type. The app has no JS unit-test harness (vitest/jest) configured; the only browser layer is the Playwright `test:e2e` project, which needs the running app + OpenRegister data. The seven-tab integration is verified statically (imports, component registration, `activeTab` dispatch, store calls). Browser smoke run to be executed against the dev instance during opsx-verify. — deferred to downstream cycle / fleet-wide adoption (handoff)
- **Spec ref**: ADR-008 smoke testing rules
- **Deferred reason**: no live instance / seed data available in the build worktree.
