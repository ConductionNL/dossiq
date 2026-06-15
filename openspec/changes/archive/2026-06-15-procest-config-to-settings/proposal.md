# Proposal: procest-config-to-settings

kind: navigation / IA dedup refactor — cites **ADR-037** (modular config fragments + canonical nav
layout in `src/menu-layout.json` decides WHERE entries live, fragments decide WHAT exists),
**ADR-022** (apps-consume-or-abstractions: config/types/definitions belong under a Settings group,
not as top-level transactional nav — the docudesk IA model) and **ADR-012** (deduplication: this
change proves it is not re-implementing an existing capability — it only relocates existing,
already-routable pages).

## Summary

procest's left-hand navigation mixes **day-to-day case work** (Cases, My Work, Bezwaren, Beroepen,
Voorstellen, Analytics, the operational Termijnbewaking dashboard) with **configuration / admin
surfaces** (case types, fee schedules, tenants, parafeerroutes, map layers, workflow definitions,
automatic actions, the handhavingsstrategie matrix, tenant onboarding). Today the config surfaces are
spread across the nav as ~14 flat siblings each individually flagged `"section": "settings"`, plus
two genuinely **top-level** config leaves that carry no section at all:

- `LegesVerordeningen` (the leges *tariff-table admin* fragment `src/manifest.d/30-leges.json`,
  `order: 75`, **no `section`** → renders top-level in the transactional nav), and
- the second leges admin entry below it.

This change introduces a single canonical **Settings group** (`SettingsGroup`) and relocates every
configuration/admin leaf under it through `src/menu-layout.json#relocations` — the same mechanism
that already dissolves `Cases`/`Werkvoorraad` into `CasesGroup` and `Bezwaren`/`Beroepen` into
`BezwaarBeroepGroup`. Every page stays **routable** (deep links + e2e specs keep working); only the
nav *placement* changes. No schema, controller, service, or page-route changes.

The brief's "Legesberekeningen vs Legesverordeningen" and "Termijnbewaking" nuances are honoured
(see Phase 0 / design): fee **schedules/definitions** and term **definitions** are config and move to
Settings; live fee **calculations** (`Legesberekeningen`, per-case calculation output) and the
operational **Termijnbewaking dashboard** are operational output and STAY in the working nav.

## Why

- **IA clarity (ADR-022 docudesk model).** Configuration, types, definitions and integrations belong
  under a Settings group, not interleaved with the case-handler's daily work. A behandelaar opening
  procest should see Cases / My Work / Bezwaren first, not "Parafeerroutes" and "Kaartlagen".
- **A canonical place to decide WHERE (ADR-037).** procest already owns `src/menu-layout.json` as the
  single canonical layout file. Two config leaves bypass it by living top-level without a section, and
  the `section: "settings"` flat list is not a real *group*. Folding them into one relocated
  `SettingsGroup` makes the layout file the single source of truth for placement.
- **No new capability, no duplication (ADR-012).** This is purely a relocation of existing,
  already-built, already-routable pages. Phase 0 confirms no config page is being recreated and no
  operational surface is being demoted.

## What

1. **Add a `SettingsGroup` parent menu node** (no `route` — a group shell) to the base manifest menu,
   ordered last in the primary nav, icon `icon-settings`.
2. **Relocate all configuration/admin leaves under `SettingsGroup`** via `src/menu-layout.json#relocations`
   (`sourceId -> "SettingsGroup"`): `CaseTypesMenu`, `LegesverordeningenMenu`, `PartnersMenu`,
   `TenantsMenu`, `ParafeerroutesMenu`, `WmsLayersMenu`, `WorkflowDefinitionsMenu`,
   `AutomaticActionsMenu`, `LhsMatricesMenu`, `LhsRecommendationsMenu`, `LocationsMenu`,
   `StatusRecordsMenu`, `BezwaarCommitteesMenu`, `ArchiefDashboardMenu`, `TenantOnboardingMenu`,
   `SubstitutionMenu`, `SubstitutionAdminMenu`, `SettingsMenu`, plus the two top-level leges fragment
   leaves `LegesVerordeningen` (and its sibling in `30-leges.json`).
3. **Keep operational surfaces in the working nav.** `Legesberekeningen` (`LegesberekeningenMenu`,
   live per-case fee-calculation output) is **moved OUT of `section: settings`** into the working nav,
   and the `TermijnDashboardMenu` operational dashboard **stays** relocated to `AnalyticsGroup`
   (unchanged). No config edit touches these beyond the `Legesberekeningen` section correction.
4. **Pages stay routable.** No page id, route, type or component changes. The `Settings` page
   (`route:/settings`) and every config index/detail page remain reachable by deep link and e2e.

## Capabilities

### New Capabilities

- `procest-config-to-settings`: a canonical navigation contract that all procest configuration/admin
  surfaces live under one `SettingsGroup` (via `src/menu-layout.json` relocations), operational case
  surfaces stay in the working nav, and every relocated page remains routable.

### Modified Capabilities

- None — no existing capability's behaviour changes; only nav placement.

## Affected Projects

- [x] Project: `procest` — all edits are in `src/manifest.json`, `src/manifest.d/30-leges.json`
  and `src/menu-layout.json`.

## Out of Scope

- Any schema / controller / service / business-logic change (this is nav-only).
- Renaming or merging config pages, or changing their routes.
- Moving operational case work, the Termijnbewaking analytics dashboard, or live Legesberekeningen
  output into Settings.
- The NC server-side admin-settings panel (`#[AuthorizedAdminSetting]` controllers) — unchanged.

## Success Criteria

- `openspec validate procest-config-to-settings --strict` exits 0.
- After the change, every configuration/admin leaf renders as a child of a single `SettingsGroup`
  node, and no config leaf renders at the top level of the primary nav.
- `Legesberekeningen` and the Termijnbewaking dashboard remain in the working (non-settings) nav.
- Every relocated page (`/legesverordeningen`, `/settings/tenants`, `/settings/parafeerroutes`,
  `/settings/wms-layers`, `/settings/workflow-definitions`, `/settings/automatic-actions`,
  `/settings/lhs-matrices`, `/tenant-onboarding`, `/leges/verordeningen`, `/settings`, …) is still
  reachable by direct URL (routable) after the relocation.

**Depends on:** nothing — procest already owns `src/menu-layout.json` and the relocation engine
(`applyMenuRelocations` in `src/main.js`). This change reuses that mechanism only.
