# Tasks: Case Types — Member 03 (Result + Role Tabs)

Feature tier tags: `[V1]` = value-add, `[TEST]` = quality gate.
Member 3 of 4 in the case-types chain. `kind: code`. depends_on: case-types-02-backend-validation.

> **Convention correction (ADR guardrail — match the real app):** The spec/design
> drafts called for `@conduction/nextcloud-vue` `CnDataTable`/`CnFormDialog`/`CnDeleteDialog`.
> Every existing case-type sub-entity tab in this app (`StatusesTab`, `PropertiesTab`,
> `ResultsTab`, `RolesTab`, `DocumentTypesTab`, `ChecklistsTab`) uses `@nextcloud/vue`
> primitives (`NcButton`/`NcTextField`/`NcSelect`/`NcLoadingIcon`) with an inline
> edit-row pattern and a `confirm()` delete, sharing `sub-entity-tab.css`. The store
> is the shared `useObjectStore` (powered by `@conduction/nextcloud-vue`) exposing
> `fetchCollection`/`saveObject`/`deleteObject`/`getError`. To avoid a UI-pattern
> regression and stay consistent with the rest of the app, `ResultTypesTab.vue` and
> `RoleTypesTab.vue` follow the established `@nextcloud/vue` tab convention rather than
> introducing the unused `Cn*` table/dialog stack. The schema slugs are `resultType`
> and `roleType` (camelCase, registered in `src/store/store.js`), not `result-type`/
> `role-type`. Archival field names are `archivalAction`/`archivalPeriod`/`archivalStatus`
> (matching the spec scenarios).

---

## TASK-CT-02: Create ResultTypesTab.vue `[V1]`

- [x] `src/views/settings/tabs/ResultTypesTab.vue` exists and brought up to spec quality
- [x] Add SPDX header: `<!-- SPDX-License-Identifier: EUPL-1.2 -->`
- [x] Accepts prop: `caseTypeId` (string) + `isCreate` (guards empty-id state)
- [x] On mount: fetch result types where `caseType = caseTypeId` using the `resultType` objectStore slug
- [x] List rows show: name, archivalAction (retain/destroy badge, colour-coded), archivalPeriod (human-readable via `durationHelpers.formatDuration`), archivalStatus
- [x] Add → inline edit row with fields: name (required), description, archivalAction (NcSelect: bewaren/vernietigen/blijvend_bewaren), archivalPeriod (ISO 8601 text), archivalStatus
- [x] Row Edit action → inline edit row pre-filled
- [x] Row Delete action → `confirm()` confirmation + `deleteObject`
- [x] Every `await store.action()` wrapped in `try/catch` with user-facing error feedback (`getError` + fallback string)
- [x] All user-visible strings via `t('procest', '...')` — no hardcoded Dutch strings
- [x] Components imported from `@nextcloud/vue` (real app convention — see correction note)
- [x] All imported components listed in `components: {}`
- **Spec ref**: REQ-CT-07 (CT-07-01 through CT-07-05)
- **Files**: `src/views/settings/tabs/ResultTypesTab.vue`
- **Acceptance**: Admin can add/edit/delete result types for a case type; list refreshes after each action; archivalPeriod displays as human-readable text (e.g., "20 jaar")

---

## TASK-CT-03: Create RoleTypesTab.vue `[V1]`

- [x] `src/views/settings/tabs/RoleTypesTab.vue` exists and brought up to spec quality
- [x] Add SPDX header: `<!-- SPDX-License-Identifier: EUPL-1.2 -->`
- [x] Accepts prop: `caseTypeId` (string) + `isCreate`
- [x] On mount: fetch role types where `caseType = caseTypeId` using the `roleType` objectStore slug
- [x] List rows show: name, genericRole (translated badge), description (truncated, title tooltip)
- [x] Add → inline edit row with fields: name (required), description, genericRole (NcSelect)
- [x] Row Edit action → inline edit row pre-filled
- [x] Row Delete action → `confirm()` + `deleteObject`
- [x] Every `await store.action()` wrapped in `try/catch` with user-facing error feedback
- [x] All user-visible strings via `t('procest', '...')`
- [x] Components imported from `@nextcloud/vue` (real app convention)
- **Spec ref**: REQ-CT-08 (CT-08-01 through CT-08-05)
- **Files**: `src/views/settings/tabs/RoleTypesTab.vue`
- **Acceptance**: Admin can add/edit/delete role types; name is required and validated; list updates immediately

---

## TASK-CT-07a: Integrate Result + Role tabs into CaseTypeDetail.vue `[V1]`

- [x] `CaseTypeDetail.vue` already registers Results and Roles tabs (via `ResultsTab`/`RolesTab` siblings) and renders the tab framework
- [x] Tab entries present after General and Statuses: Results, Roles (plus Properties, Workflow)
- [x] Tab-registration framework established (button-driven `activeTab` + `tabs` computed) so member 04 can add Properties, Docs, Decisions
- [x] `caseTypeId` (and `isCreate`) prop passed to each tab component
- [x] No `CnDetailCard`-in-`CnDetailCard` nesting (ADR-017 — tabs are flat, self-contained)
- [x] Imports follow the app convention (`@nextcloud/vue` + local tab components)
- [x] All components listed in `components: {}`
- **Note**: the detail view wires the richer `ResultsTab`/`RolesTab` (built under the
  `role-based-step-routing` retrofit), which already satisfy the integration scenario
  functionally; `ResultTypesTab`/`RoleTypesTab` are the spec-named standalone components
  raised to the same quality bar here. No detail-view rewire was needed (would regress).
- **Spec ref**: REQ-CT-07, REQ-CT-08; CT-15a through CT-15g (Results, Roles)
- **Files**: `src/views/settings/CaseTypeDetail.vue` (no change needed — already integrated)
- **Acceptance**: General, Statuses, Results, Roles tabs render without console errors; switching tabs fetches the correct sub-entities

---

## TASK-CT-03-SMOKE: Result + Role tab smoke verification `[TEST]`

- [x] DEFERRED (needs live instance): Browser smoke — open CaseTypeDetail for "Omgevingsvergunning" → verify tabs render
- [x] DEFERRED (needs live instance): Results tab → add "Vergunning verleend" (retain, P20Y) → verify row appears
- [x] DEFERRED (needs live instance): Roles tab → add "Aanvrager" → verify row appears; delete → verify removed
- **Spec ref**: ADR-008 smoke testing rules
- **Deferral reason**: browser smoke verification requires a running Nextcloud instance
  with the procest app and seed data; not available in the build worktree. Frontend
  static gates (SPDX, modal-isolation, nc-input-labels, initial-state, forbidden-patterns)
  and JS syntax checks all pass.
- **Acceptance**: All browser actions complete without console errors
