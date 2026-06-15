# Design — procest config surfaces into a Settings group

## Context

procest is a ZGW case-management app. Its primary left-hand nav (from `src/manifest.json#menu`,
merged with `src/manifest.d/*.json` fragments, then reshaped by `src/menu-layout.json` via
`applyMenuRelocations`/`applyMenuRemovals` in `src/main.js`) currently interleaves the
behandelaar's daily case work with ~14 configuration/admin surfaces. Some config leaves already
carry `"section": "settings"` (a render hint that drops them in the NC app-nav Settings section),
but they are still **flat siblings**, not a real group, and two leges admin leaves bypass even
that — they are genuinely top-level.

ADR-037 makes `src/menu-layout.json` the single canonical place deciding WHERE entries live;
fragments decide WHAT exists. The good IA model (docudesk, ADR-022) is: config/types/definitions
under ONE Settings group; transactional work at the top.

## Phase 0 classification — config vs operational (the load-bearing decision)

| # | Surface (label) | Menu id | Source | Today | Classification | Action |
|---|---|---|---|---|---|---|
| 1 | Case Types | `CaseTypesMenu` | manifest.json (route `Settings`) | `section:settings` | **config** (case-type definitions) | relocate → `SettingsGroup` |
| 2 | Legesverordeningen (fee SCHEDULES) | `LegesverordeningenMenu` | manifest.json `/legesverordeningen` | `section:settings` | **config** (tariff schedules/definitions) | relocate → `SettingsGroup` |
| 3 | Legesberekeningen (fee CALCULATIONS) | `LegesberekeningenMenu` | manifest.json `/legesberekeningen` | `section:settings` | **OPERATIONAL** — per-case calc output (`case`,`total`,`status`,`calculatedBy`,`calculatedAt`) | move OUT of settings → working nav |
| 4 | Tenants | `TenantsMenu` | manifest.json `/settings/tenants` | `section:settings`, admin | **config** (multi-tenant admin) | relocate → `SettingsGroup` |
| 5 | Parafeerroutes | `ParafeerroutesMenu` | manifest.json `/settings/parafeerroutes` | `section:settings` | **config** (signing-route definitions) | relocate → `SettingsGroup` |
| 6 | Kaartlagen (map layers) | `WmsLayersMenu` | manifest.json `/settings/wms-layers` | `section:settings`, admin | **config** (WMS/WFS layer definitions) | relocate → `SettingsGroup` |
| 7 | Workflow definitions | `WorkflowDefinitionsMenu` | manifest.json `/settings/workflow-definitions` | `section:settings` | **config** (workflow templates) | relocate → `SettingsGroup` |
| 8 | Automatische acties | `AutomaticActionsMenu` | manifest.json `/settings/automatic-actions` | `section:settings` | **config** (automatic-action definitions) | relocate → `SettingsGroup` |
| 9 | Handhavingsstrategie | `LhsMatricesMenu` | manifest.json `/settings/lhs-matrices` | `section:settings`, admin | **config** (LHS strategy matrix) | relocate → `SettingsGroup` |
| 10 | Tenant onboarding | `TenantOnboardingMenu` | manifest.json `/tenant-onboarding` | `section:settings` | **config** (provisioning admin) | relocate → `SettingsGroup` |
| 11 | Termijnbewaking (config) | — | `TermijnDefinitiesTab.vue` inside `Settings` AdminRoot | already inside Settings page | **config** — but ALREADY inside the Settings page as a tab; no separate nav leaf | no nav change needed |
| — | Termijnbewaking (DASHBOARD) | `TermijnDashboardMenu` | manifest.json `/termijn-dashboard` | relocated → `AnalyticsGroup` | **OPERATIONAL** — KPI dashboard (total cases, within-term %, avg duration) | STAYS in `AnalyticsGroup` |

Adjacent config leaves folded in for a coherent group (already `section:settings`, same IA class):
`PartnersMenu`, `LhsRecommendationsMenu` (LHS output review — see note), `LocationsMenu`,
`StatusRecordsMenu`, `BezwaarCommitteesMenu`, `ArchiefDashboardMenu`, `SubstitutionMenu`,
`SubstitutionAdminMenu`, `SettingsMenu`. The two top-level leges admin leaves in
`src/manifest.d/30-leges.json` (`LegesVerordeningen`, `order:75`, no section) are folded in too.

### Two nuances the brief called out, resolved

- **Legesberekeningen vs Legesverordeningen.** `legesverordening` rows are tariff *schedules*
  (name/year/effectiveDate/status/isActive) — configuration → Settings. `legesberekening` rows are
  live per-case *calculation output* (case/total/version/status/calculatedBy/calculatedAt) —
  operational. So #2 → Settings, #3 → working nav. This is the one item the brief flagged, and it is
  the only item we move OUT of `section:settings`.
- **Termijnbewaking.** The *config* (statutory term definitions per zaaktype) is `TermijnDefinitiesTab.vue`,
  already a tab inside the `Settings` page — no separate nav leaf to move. The top-level
  `TermijnDashboardMenu` (`/termijn-dashboard`) is the *operational* AWB-termijnbewaking KPI dashboard,
  already relocated to `AnalyticsGroup`. It stays operational. So there is NO new Settings entry for
  Termijnbewaking; the brief's "deadline monitoring config" already lives in Settings.

> Note on `LhsRecommendationsMenu`: LHS recommendations are arguably operational review output. It is
> already `section:settings` today and the recommendation list is an admin/config-review surface
> (matrix-driven), so it is kept under Settings to avoid scope creep; if product later judges it
> operational, a one-line relocation removal moves it back. Flagged, not silently reclassified.

## Key decisions

1. **Introduce a real `SettingsGroup` parent, relocate via `menu-layout.json` (ADR-037).** Rather
   than leave a flat `section:settings` list, add one group shell `SettingsGroup` (no `route`) and use
   the existing `relocations` map to dissolve every config leaf into it — identical to how
   `CasesGroup`/`BezwaarBeroepGroup`/`AnalyticsGroup` already work. `applyMenuRelocations` keeps a
   relocated leaf at top level if its target group is missing ("nothing silently disappears"), so
   adding the group first is required and is part of this change.
2. **Pages stay routable; this is nav-only.** No page `id`/`route`/`type`/`component` changes. Per the
   menu-layout contract, relocation/removal never touches page routes — deep links and e2e specs that
   navigate by route keep working.
3. **`Legesberekeningen` correction is a manifest edit, not a relocation.** Because it is currently
   `section:settings` in `manifest.json`, the fix is to drop its `"section":"settings"` so it renders
   in the working nav (and give it a sensible `order`). It is NOT added to the relocations map.
4. **Top-level leges fragment leaves move via relocations too.** `src/manifest.d/30-leges.json`'s
   `LegesVerordeningen` leaf has no `section`; we add it to the `relocations` map so it folds into
   `SettingsGroup` without editing the fragment's page (the page route `/leges/verordeningen` stays).

## Exact edits

### A. `src/manifest.json` — add the group shell + correct Legesberekeningen

```jsonc
// ADD to "menu": a group shell (no route), ordered after the last working cluster
{ "id": "SettingsGroup", "label": "Settings", "icon": "icon-settings", "order": 200 }

// CHANGE LegesberekeningenMenu: remove "section":"settings", keep a working-nav order
{ "id": "LegesberekeningenMenu", "label": "Legesberekeningen", "icon": "icon-category-monitoring",
  "route": "Legesberekeningen", "order": 50 }   // was: "section":"settings","order":97
```

### B. `src/menu-layout.json#relocations` — fold config leaves into SettingsGroup

Add these `sourceId -> "SettingsGroup"` entries (leaves move under the group; the group renders once):

```jsonc
"CaseTypesMenu": "SettingsGroup",
"LegesverordeningenMenu": "SettingsGroup",
"PartnersMenu": "SettingsGroup",
"TenantsMenu": "SettingsGroup",
"ParafeerroutesMenu": "SettingsGroup",
"WmsLayersMenu": "SettingsGroup",
"WorkflowDefinitionsMenu": "SettingsGroup",
"AutomaticActionsMenu": "SettingsGroup",
"LhsMatricesMenu": "SettingsGroup",
"LhsRecommendationsMenu": "SettingsGroup",
"LocationsMenu": "SettingsGroup",
"StatusRecordsMenu": "SettingsGroup",
"BezwaarCommitteesMenu": "SettingsGroup",
"ArchiefDashboardMenu": "SettingsGroup",
"TenantOnboardingMenu": "SettingsGroup",
"SubstitutionMenu": "SettingsGroup",
"SubstitutionAdminMenu": "SettingsGroup",
"SettingsMenu": "SettingsGroup",
"LegesVerordeningen": "SettingsGroup"
```

(`TermijnDashboardMenu` and `Legesberekeningen` are deliberately NOT in this list — they stay
operational. `LegesberekeningenMenu` is corrected via edit A, not a relocation.)

## Alternatives considered

- **Leave the `section:"settings"` flat list as-is.** Rejected: it is not a real group (no single
  collapsible Settings node), and it does not cover the two top-level leges leaves. ADR-037 wants one
  canonical placement decision.
- **Move pages' routes under `/settings/*` to match.** Rejected: route changes break deep links and
  e2e specs; the menu-layout contract explicitly keeps pages routable while only the nav moves.
- **Reclassify Legesberekeningen / LhsRecommendations as config too.** Rejected for Legesberekeningen
  (clearly per-case live output); LhsRecommendations kept under Settings but flagged for product.

## Migration / rollout

Pure front-end nav reshape; no data migration, no `lib/Repair/*` step, no schema change. Rollout is a
rebuild of the procest bundle. Reversible by removing the added relocations + group shell and
restoring `Legesberekeningen`'s `section`.

## Risks

- **Group target missing → leaf stays top-level.** Mitigated: edit A adds `SettingsGroup` before the
  relocations reference it; `applyMenuRelocations` is defensive (keeps the leaf top-level rather than
  dropping it) so the worst case is a visible un-grouped leaf, never a vanished page.
- **An over-eager relocation hides an operational surface.** Mitigated by the Phase 0 table: only
  config leaves are listed; `Legesberekeningen` and `TermijnDashboardMenu` are explicitly excluded.
- **e2e navigates by menu structure rather than route.** Low: procest e2e navigates by route; pages
  stay routable. Spec REQ-PCTS-004 asserts routability explicitly.
