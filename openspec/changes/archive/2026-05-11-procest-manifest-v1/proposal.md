# Procest — manifest v1: migrate to JSON manifest renderer + CnAppRoot

## Why

Procest currently boots through a hand-written `src/main.js` + `src/App.vue`
shell that imports `MainMenu.vue` (NcAppNavigation) and a per-page Vue file
table in `src/router/index.js`. Each route is a bespoke component under
`src/views/`. There is no `src/manifest.json` — every route, every menu
item, and every page binding is hand-coded in JavaScript.

ADR-024 (`hydra/openspec/architecture/adr-024-app-manifest.md`) mandates
the JSON manifest renderer pattern across the fleet. `@conduction/nextcloud-vue@1.0.0-beta.12`
ships the seven manifest page types (`index | detail | dashboard | logs |
settings | chat | files | custom`), `CnAppRoot`, `CnAppNav`, and
`CnPageRenderer` plus the `Vue.extend` frozen-component fix that previously
blocked manifest-driven shells in Vue 2 / vue-router 3.

Decidesk (PR https://github.com/ConductionNL/decidesk/pull/160) is the
canonical reference: 20 pages migrated from hand-written views to a
declarative manifest with `CnAppRoot` driving the navigation, vue-router
configured from `manifest.pages[*].{id, route}`, and a `customComponents.js`
registry holding only documented exceptions.

This change ports that exact pattern to procest. Procest has more
inherently-custom pages than decidesk (a real GIS map view, a
multi-tab admin root, a public appointment / status / case set), so the
custom-component fallback list is longer — but the migration shape is
identical.

## What Changes

- **Add `src/manifest.json`** — declares 17 pages bound to the
  `procest` register. Page-type breakdown: 1 `dashboard`, 5 `index`,
  5 `detail`, 1 `settings`, 5 `custom`. Menu has 8 entries (top-level
  Dashboard / My Work / Werkvoorraad / Cases / Tasks / Map / Voorstellen,
  plus settings-section Documentation / Settings / Case types).

- **Rewrite `src/main.js`** to mount `<CnAppRoot>` driven by the
  bundled manifest. Keeps the mount-survivable bootstrap from
  decidesk's commit `50e4df7c`: `loadTranslations()` is fire-and-forget
  so a `/l10n/<locale>.json` 404 does not kill boot, `registerTranslations()`
  is wrapped in try/catch, and `CnPageRenderer` / `defaultPageTypes` /
  `customComponents` are shallow-cloned before being passed to vue-router
  / `CnAppRoot` props (Vue 2 `Vue.extend` mutates the component definition
  to attach `_Ctor`; non-extensible barrel exports throw without the clone).

- **Rewrite `src/App.vue`** as a thin `<CnAppRoot>` host that still
  shows the OpenRegister-missing empty state when the settings store
  reports `hasOpenRegisters === false`. Keeps the existing
  `CnIndexSidebar` host pattern via the `objectSidebarState` provide/inject
  channel so the lib's `index` page-type can mount the sidebar through
  `<CnAppRoot>`'s `#sidebar` slot.

- **Build vue-router from the manifest.** A `routesFromManifest()` helper
  in `main.js` maps each `pages[]` entry to a `{ name, path, component:
  RoutePageRenderer, props: path.includes(':') }` route. Catch-all `*`
  redirect to `/` preserved.

- **Add `src/customComponents.js`** registering the five surviving
  custom pages (`MyWorkView`, `WerkvoorraadView`, `CaseMapView`,
  `DoorlooptijdView`, `VoorstellenView`, `VoorstelDetailView`,
  `PublicCaseView`, `PublicAppointmentPage`, `PublicStatusPage`,
  `AdminRoot` for Settings). See `design.md` for the per-entry
  justification.

- **Bump `package.json`** `@conduction/nextcloud-vue` from `^1.0.0-beta.6`
  to `^1.0.0-beta.12`. The bumped lib ships the `Vue.extend` frozen-component
  fix and the seven page types ADR-024 expects.

- **Document the `@nextcloud/axios` pin** in `webpack.config.js`
  matching decidesk's working setup — the existing `package.json`
  `overrides` pin to `~2.5.2` ships both `import` and `require`
  export conditions, so `@nextcloud/vue`'s CJS bundle resolves
  `require('@nextcloud/axios')` without an alias. (An earlier draft
  of decidesk's PR added an `@nextcloud/axios$` alias; the merged
  version dropped it once the pin proved sufficient.)

- **Mirror `l10n/en_US.json` from `l10n/en.json`** so users on the
  `en_US` locale don't 404 the locale fetch (matches decidesk's
  commit `50e4df7c`).

- **Add `tests/validate-manifest.js`** — schema-validation script
  cloned from decidesk's, points at the bundled lib's
  `node_modules/@conduction/nextcloud-vue/src/schemas/app-manifest.schema.json`.

- **Delete obsolete views** for the 11 pages that the manifest
  fully supersedes (Dashboard.vue and the per-schema list/detail
  pairs for cases / tasks / voorstellen / settings). Five views
  survive as `customComponents` registry entries (MyWork, Werkvoorraad,
  CaseMapView, DoorlooptijdDashboard, plus settings AdminRoot).
  Public views and complaint views remain as additional custom registry
  entries — see "Custom-fallback inventory" in `design.md`.

- **Bump `appinfo/info.xml` `<version>` from `0.1.10` to `0.2.0`**
  (minor bump marking the manifest migration).

## Custom-fallback inventory

Pages that stay `type: "custom"` after this change:

| Page id | Reason | Category |
|---|---|---|
| `MyWork` | Bespoke filter-tab UI (4 tabs, completed toggle, per-tab counts, mixed case+task list with type badges). Doesn't fit `index`. | Genuine exception |
| `Werkvoorraad` | KPI-strip-driven work queue with click-to-filter behaviour. Renders mixed entity types in the same table. Doesn't fit `index`. | Genuine exception |
| `CaseMap` | Leaflet map view with WMS/WFS layers, marker clusters, draw tooling. No abstract analogue. | Genuine exception |
| `Doorlooptijd` | KPI dashboard for SLA compliance with apexcharts. Could become `dashboard` if `chart` widget shipped — currently no widget primitive matches. | Lib gap |
| `Voorstellen` / `VoorstelDetail` | B&W proposal workflow: filter tabs (status), special parafeerroute integration. Could become `index`/`detail` after a follow-up; deferred. | Migration cost |
| `PublicCase` / `PublicStatus` / `PublicAppointment` | Anonymous-public routes mounted under different appshell (no NcContent, no auth). Don't fit any built-in. | Genuine exception |
| `Settings` (AdminRoot) | Multi-tab admin root: case types, map layers, parafeerroute, partners, ZGW mapping, workflow editor. The lib's `type: "settings"` `widgets[]` shape doesn't yet host the `WorkflowEditor` or `CaseTypeAdmin` complex components. | Lib gap |

Total: 5 single-page customs + AdminRoot + 3 public pages. The dashboard
is migrated optimistically to `type: "dashboard"`; if widget validation
fails at runtime the fallback is documented in `design.md`'s "Cleanup
follow-up".

## Capabilities

### Modified Capabilities

- `procest-app-shell`: introduce manifest-driven routing and navigation;
  replace MainMenu / hand-written router with `CnAppRoot` + manifest.

### New Capabilities

*(none — this is a structural refactor, no new user-facing features.)*

## Impact

- **Modified files**:
  - `procest/src/main.js` — replace ad-hoc Vue bootstrap with
    `CnAppRoot` mount + `routesFromManifest()` + mount-survivable
    translation loading.
  - `procest/src/App.vue` — `<CnAppRoot>` host with OpenRegister-missing
    empty state.
  - `procest/webpack.config.js` — add `@nextcloud/axios$` alias.
  - `procest/package.json` — bump `@conduction/nextcloud-vue` to
    `^1.0.0-beta.12`.
  - `procest/appinfo/info.xml` — `<version>` 0.1.10 → 0.2.0.
  - `procest/openspec/changes/procest-manifest-v1/{proposal,design,tasks}.md`
    and `specs/procest-manifest-v1/spec.md`.

- **New files**:
  - `procest/src/manifest.json` — declarative routing/navigation.
  - `procest/src/customComponents.js` — surviving custom registry.
  - `procest/tests/validate-manifest.js` — schema validator.
  - `procest/l10n/en_US.json` — mirror of `en.json`.

- **Deleted files** (replaced by manifest renderer):
  - `procest/src/views/Dashboard.vue` (→ `type: "dashboard"`)
  - `procest/src/views/cases/CaseList.vue` (→ `type: "index"`)
  - `procest/src/views/cases/CaseDetail.vue` (→ `type: "detail"`)
  - `procest/src/views/tasks/TaskList.vue` (→ `type: "index"`)
  - `procest/src/views/tasks/TaskDetail.vue` (→ `type: "detail"`)
  - `procest/src/views/complaints/ComplaintList.vue` (→ `type: "index"`)
  - `procest/src/views/complaints/ComplaintDetail.vue` (→ `type: "detail"`)
  - `procest/src/router/index.js` (folded into `main.js`)
  - `procest/src/navigation/MainMenu.vue` (replaced by `CnAppNav`)

- **Surviving** (registered as `customComponents`):
  - `procest/src/views/MyWork.vue`
  - `procest/src/views/Werkvoorraad.vue`
  - `procest/src/views/CaseMapView.vue`
  - `procest/src/views/DoorlooptijdDashboard.vue`
  - `procest/src/views/voorstellen/VoorstelList.vue`
  - `procest/src/views/voorstellen/VoorstelDetail.vue`
  - `procest/src/views/settings/AdminRoot.vue`
  - `procest/src/views/public/PublicCaseView.vue`
  - `procest/src/views/public/PublicStatusPage.vue`
  - `procest/src/views/public/PublicAppointmentPage.vue`

- **Validates against**:
  - `node_modules/@conduction/nextcloud-vue/src/schemas/app-manifest.schema.json` (v1.2.0).

## Risks

- **`CaseDetail` widget overrides may be lost.** `CaseDetail.vue` has
  bespoke parent-case breadcrumb logic, custom save handler and
  parafeerroute action injection. The default `type: "detail"` renderer
  doesn't expose those. Mitigated by leaving `CaseDetail.vue` in the
  custom registry as a TODO if the abstract detail page can't carry
  its weight (downgrade in follow-up).

- **Voorstellen workflow.** Voorstel detail has a parafeerroute flow
  (multi-step approver wiring) that the abstract `detail` page won't
  reproduce. Kept as `custom` for v1; deferred to a follow-up
  `procest-voorstel-v2` change.

- **CnAppRoot navigation may not match menu sections perfectly.**
  The original `MainMenu.vue` mixed top-level + settings-section items.
  `CnAppNav` reads `section: "settings"` from `manifest.menu[]` — every
  menu item is verified.

- **vue-router catch-all behaviour.** The original router redirects
  `*` → `/`. Same redirect preserved in `routesFromManifest()`.

## Out of scope

- **CnAppRoot adoption for the `Settings` page itself.** Settings remains
  `custom` because the lib's `type: "settings"` `widgets[]` shape can't
  yet host `WorkflowEditor` or `CaseTypeAdmin`. Migration deferred to
  `procest-settings-rich-sections` follow-up once the lib gains a
  custom-component slot in settings sections.

- **Dashboard widget normalisation.** Dashboard migrates optimistically
  with `widgets[]` of `type: "custom"` mapping to per-id sub-components.
  The bespoke widget components (KPI cards, status chart, deadline
  alerts, etc.) stay in `src/views/dashboard/` but are no longer wired
  through the page-shell — they wire into the dashboard's `widgets[]`
  config inside the manifest. If widget normalisation breaks runtime,
  Dashboard downgrades to `type: "custom"` (tracked in `design.md`
  Open Question 1).

- **i18n translation header refactor.** Stays as the existing
  `loadTranslations` flow (now fire-and-forget). Multi-language UI
  picker is parked for `procest-i18n-v1`.

- **Public route shell.** The three `/public/...` routes mount under
  the same `CnAppRoot` for now; if the lib doesn't gracefully handle
  unauthenticated context, those routes get split out to a separate
  bundle in a follow-up.

## See also

- `hydra/openspec/architecture/adr-024-app-manifest.md` — fleet-wide
  manifest convention.
- Decidesk reference PR: https://github.com/ConductionNL/decidesk/pull/160
- Decidesk source-of-truth: `decidesk/openspec/changes/decidesk-manifest-v1/`
- `@conduction/nextcloud-vue@1.0.0-beta.12` — published lib including
  `Vue.extend` frozen-component fix.
