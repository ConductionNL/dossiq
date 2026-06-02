# Tasks: Case Types — Member 03 (Result + Role Tabs)

Feature tier tags: `[V1]` = value-add, `[TEST]` = quality gate.
Member 3 of 4 in the case-types chain. `kind: code`. depends_on: case-types-02-backend-validation.

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

## TASK-CT-07a: Integrate Result + Role tabs into CaseTypeDetail.vue `[V1]`

- [ ] Import and register `ResultTypesTab` and `RoleTypesTab` in `CaseTypeDetail.vue`
- [ ] Add tab entries in `NcTabPanel`: Results, Roles (after existing General and Statuses tabs)
- [ ] Establish the tab-registration framework so member 04 can add Properties, Docs, Decisions
- [ ] Pass `caseTypeId` prop to each new tab component
- [ ] Verify no `CnDetailCard`-in-`CnDetailCard` nesting (ADR-017 — self-contained components)
- [ ] All new imports from `@conduction/nextcloud-vue` only
- [ ] All new components listed in `components: {}`
- **Spec ref**: REQ-CT-07, REQ-CT-08; CT-15a through CT-15g (Results, Roles)
- **Files**: `src/views/settings/CaseTypeDetail.vue`
- **Acceptance**: General, Statuses, Results, Roles tabs render without console errors; switching tabs fetches the correct sub-entities

---

## TASK-CT-03-SMOKE: Result + Role tab smoke verification `[TEST]`

- [ ] Browser: open CaseTypeDetail for "Omgevingsvergunning" → verify General, Statuses, Results, Roles tabs render
- [ ] Browser: Results tab → add "Vergunning verleend" (retain, P20Y) → verify row appears
- [ ] Browser: Roles tab → add "Aanvrager" → verify row appears; delete it → verify removed
- **Spec ref**: ADR-008 smoke testing rules
- **Acceptance**: All browser actions complete without console errors
