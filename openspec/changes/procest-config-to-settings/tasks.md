# Tasks — procest config surfaces into a Settings group

## Phase 0: Deduplication Check (ADR-012)

- [x] Confirm this change creates **no new page, schema, controller, service, or business logic** —
  it only relocates existing, already-routable menu leaves and corrects one `section` flag.
- [x] Confirm the config-vs-operational classification table (design.md Phase 0) against
  `src/manifest.json#menu` and `src/manifest.d/30-leges.json`: every relocated leaf is configuration;
  `Legesberekeningen` (live per-case fee output) and `TermijnDashboardMenu` (operational KPI
  dashboard) are excluded and stay in the working nav.
- [x] Confirm procest already owns the relocation mechanism (`src/menu-layout.json` +
  `applyMenuRelocations`/`applyMenuRemovals` in `src/main.js`) — no new infra is introduced.
- [x] Confirm the Termijnbewaking *config* already lives inside the `Settings` page as
  `TermijnDefinitiesTab.vue` — no new Settings nav leaf is created for it.

## Phase 1: Add the Settings group shell (ADR-037)

- [x] In `src/manifest.json#menu`, add a group shell node:
  `{ "id": "SettingsGroup", "label": "Settings", "icon": "icon-settings", "order": 200 }` (no
  `route` — a pure group; ordered last in the primary nav).
- [x] Do not change any page in `src/manifest.json#pages`.

## Phase 2: Relocate config leaves under SettingsGroup (ADR-037, ADR-022)

- [x] In `src/menu-layout.json#relocations`, add `sourceId -> "SettingsGroup"` for each config leaf:
  `CaseTypesMenu`, `LegesverordeningenMenu`, `PartnersMenu`, `TenantsMenu`, `ParafeerroutesMenu`,
  `WmsLayersMenu`, `WorkflowDefinitionsMenu`, `AutomaticActionsMenu`, `LhsMatricesMenu`,
  `LhsRecommendationsMenu`, `LocationsMenu`, `StatusRecordsMenu`, `BezwaarCommitteesMenu`,
  `ArchiefDashboardMenu`, `TenantOnboardingMenu`, `SubstitutionMenu`, `SubstitutionAdminMenu`,
  `SettingsMenu`, and the top-level leges fragment leaf `LegesVerordeningen`.
- [x] Do NOT add `TermijnDashboardMenu` or `Legesberekeningen`/`LegesberekeningenMenu` to the
  relocations map.

## Phase 3: Keep operational surfaces in the working nav (ADR-022)

- [x] In `src/manifest.json#menu`, change `LegesberekeningenMenu`: remove `"section": "settings"` and
  set a working-nav order (e.g. `"order": 50`) so the live fee-calculation list renders with the
  case-handler's daily work, not in Settings.
- [x] Verify `TermijnDashboardMenu` remains relocated to `AnalyticsGroup` (no edit) — operational.

## Phase 4: Verify routability (no page-route change)

- [x] Confirm no page `id`/`route`/`type`/`component` was changed in `src/manifest.json` or
  `src/manifest.d/30-leges.json`.
- [x] Confirm each relocated page is still reachable by direct URL: `/legesverordeningen`,
  `/settings/tenants`, `/settings/parafeerroutes`, `/settings/wms-layers`,
  `/settings/workflow-definitions`, `/settings/automatic-actions`, `/settings/lhs-matrices`,
  `/tenant-onboarding`, `/leges/verordeningen`, `/settings`, and `/legesberekeningen`.

## Phase 5: Validate

- [x] `cd procest && openspec validate procest-config-to-settings --strict` exits 0.
- [x] (Build/visual, at apply time) Rebuild the bundle; confirm one `SettingsGroup` node holds all
  config leaves and no config leaf renders top-level; `Legesberekeningen` + Termijnbewaking dashboard
  render in the working nav.
