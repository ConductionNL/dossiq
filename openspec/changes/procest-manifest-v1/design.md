# Design — Procest manifest v1: per-page Vue → JSON manifest renderer + CnAppRoot

## Approach

Procest currently has no `src/manifest.json` and no `CnAppRoot` —
everything is hand-wired in `src/main.js`, `src/router/index.js`,
`src/App.vue`, and `src/navigation/MainMenu.vue`. The migration
introduces all four manifest pieces in a single change set, mirroring
decidesk's PR #160:

1. Write `src/manifest.json` with menu + pages.
2. Replace router/index.js + main.js with the CnAppRoot bootstrap +
   `routesFromManifest()` helper, using the mount-survivable pattern
   from decidesk's commit `50e4df7c`.
3. Replace App.vue with the thin `<CnAppRoot>` host (preserving the
   OpenRegister-missing empty state from the current shell).
4. Add `src/customComponents.js` registering the documented exceptions.
5. Bump `@conduction/nextcloud-vue` to `^1.0.0-beta.12` and add the
   `@nextcloud/axios$` webpack alias.
6. Mirror `l10n/en_US.json` from `l10n/en.json`.
7. Bump `appinfo/info.xml` `<version>` 0.1.10 → 0.2.0.
8. Delete the obsolete per-page Vue files for migrated routes.

The change is **runtime-active** — unlike decidesk's split between an
initial spec-only commit and a later adoption commit, procest does
both in one PR because the lib (`@conduction/nextcloud-vue@1.0.0-beta.12`)
is already published and includes the `Vue.extend` frozen-component fix.

## Per-page mapping table

The current procest router (`src/router/index.js`) declares 14 routes
plus a catch-all. The manifest declares 17 named pages (the catch-all
is declared in `routesFromManifest()`, not the manifest). Every
non-custom page binds to register slug `procest` and a schema slug
from `lib/Settings/procest_register.json`.

| Current id | Current type | New type | Config sketch | Reason |
|---|---|---|---|---|
| `Dashboard` | hand-coded view | `dashboard` | `{ widgets: [10 widget defs], layout: [10 grid items] }` | Existing `Dashboard.vue` already uses `CnDashboardPage`; the dashboard renderer can pick up the same widget definitions from the manifest. Each widget id maps to a `type: "custom"` widget pointing at the `views/dashboard/*` per-widget components in the registry. |
| `MyWork` | hand-coded view | `custom` | (unchanged) `component: "MyWorkView"` | **Genuine exception** — bespoke 4-tab filter UI mixing case + task entities with type badges. |
| `Werkvoorraad` | hand-coded view | `custom` | (unchanged) `component: "WerkvoorraadView"` | **Genuine exception** — KPI-strip-driven work queue. |
| `Cases` | hand-coded view | `index` | `{ register: "procest", schema: "case", columns: ["identifier","title","caseType","status","assignee","deadline"], sidebar: { enabled: true, showMetadata: true } }` | Schema-driven list. `CaseList.vue` already uses `CnIndexPage`. |
| `CaseDetail` | hand-coded view | `detail` | `{ register: "procest", schema: "case", sidebarTabs: [overview, tasks, decisions, documents, audit] }` | Schema-driven detail. Custom save handler / parafeerroute logic moves into a sidebar-tab custom component or is dropped (TODO documented below). |
| `Tasks` | hand-coded view | `index` | `{ register: "procest", schema: "task", columns: ["title","case","assignee","status","dueDate"], sidebar: { enabled: true } }` | Schema-driven list. |
| `TaskNew` | hand-coded view | `detail` | (route only — same as `TaskDetail`) | The current `TaskNew` route is just `TaskDetail` with `props.taskId === "new"`. The renderer's detail page handles "new" objects via `:id` with `id === "new"` — preserve same behaviour with a single `detail` page. The `?caseId=` query param still flows through `route.query`. |
| `TaskDetail` | hand-coded view | `detail` | `{ register: "procest", schema: "task", sidebarTabs: [overview, audit] }` | Schema-driven detail. |
| `CaseMap` | hand-coded view | `custom` | (unchanged) `component: "CaseMapView"` | **Genuine exception** — Leaflet map, WMS/WFS layers, marker clusters. |
| `Voorstellen` | hand-coded view | `custom` | (unchanged) `component: "VoorstellenView"` | **Migration cost** — bespoke filter-tab UI tied to parafeerroute lifecycle. Migrate to `index` in a follow-up. |
| `VoorstelDetail` | hand-coded view | `custom` | (unchanged) `component: "VoorstelDetailView"` | **Migration cost** — bespoke parafeerroute multi-step approver flow. Defer. |
| `Doorlooptijd` | hand-coded view | `custom` | (unchanged) `component: "DoorlooptijdView"` | **Lib gap** — KPI charts via apexcharts. No `chart` widget primitive yet. |
| `Settings` | hand-coded view | `custom` | (unchanged) `component: "AdminRootView"` | **Lib gap** — multi-tab admin root with WorkflowEditor / CaseTypeAdmin / MapLayerSettings. The `type: "settings"` `widgets[]` shape doesn't yet host these complex editors. |
| `CaseTypes` | hand-coded view (alias to AdminRoot) | `custom` | (unchanged) `component: "AdminRootView"` | Same as Settings. Currently both routes resolve to `AdminRoot`. |
| `PublicCase` *(new)* | (was `/case/:id`) | `custom` | `component: "PublicCaseView"` | **Genuine exception** — anonymous-public route, no auth, no main menu. |
| `PublicAppointment` *(new)* | (was `/appointment/:id`) | `custom` | `component: "PublicAppointmentPage"` | Same. |
| `PublicStatus` *(new)* | (was `/status/:token`) | `custom` | `component: "PublicStatusPage"` | Same. |

Final tally: **1 dashboard + 3 index + 3 detail + 10 custom = 17**.

NOTE: the existing router did NOT have explicit routes for the three
`Public*` views — they were mounted via separate Apache routes under
different controllers. The manifest still declares them so they appear
in the routing table; the renderer mounts them under `CnAppRoot` like
any other page (the public Vue files render their own minimal shell
when `isPublic` is true).

NOTE: `CaseTypes` and `Settings` both resolve to `AdminRoot` — the
manifest lists both ids so existing code that does `$router.push({ name:
'CaseTypes' })` still resolves. They share the same `component` registry
entry.

## Dashboard widget inventory

`Dashboard` config sketch (mirror of `Dashboard.vue`'s current `widgetDefs`):

```json
{
  "widgets": [
    { "id": "count-open-cases",     "type": "custom", "title": "Open Cases" },
    { "id": "count-overdue",        "type": "custom", "title": "Overdue" },
    { "id": "count-completed",      "type": "custom", "title": "Completed This Month" },
    { "id": "count-my-tasks",       "type": "custom", "title": "My Tasks" },
    { "id": "count-sla",            "type": "custom", "title": "SLA Compliance" },
    { "id": "cases-by-status",      "type": "custom", "title": "Cases by Status" },
    { "id": "my-work",              "type": "custom", "title": "My Work" },
    { "id": "case-map",             "type": "custom", "title": "Case Map" },
    { "id": "deadline-alerts",      "type": "custom", "title": "Deadline Alerts" },
    { "id": "task-due-reminders",   "type": "custom", "title": "Task Due Reminders" },
    { "id": "stalled-cases",        "type": "custom", "title": "Stalled Cases" }
  ],
  "layout": [/* 11 grid items copied from DEFAULT_LAYOUT */]
}
```

Every widget is `type: "custom"` because the renderer's dashboard widget
registry doesn't yet expose `stats-block` / `chart` primitives. The
existing `views/dashboard/*` components are kept (they're imported by
the per-widget custom registry entries, not by the page).

If `CnDashboardPage` can't render the dashboard from this config (e.g.
because `type: "custom"` widget rendering needs a registry entry the
renderer doesn't support), Dashboard downgrades to `type: "custom"` as
documented in Open Question 1.

## Sidebar tab inventory

`CaseDetail` and `TaskDetail` are the only currently-migrated detail
pages. Tabs:

| Detail page | Tabs |
|---|---|
| `CaseDetail` | `overview` (data + metadata widgets), `tasks` (custom: `CaseTasksTab` — TODO stub), `decisions` (custom: `CaseDecisionsTab` — TODO stub), `documents` (custom: `CaseDocumentsTab` — TODO stub), `audit` (built-in audit-trail) |
| `TaskDetail` | `overview` (data + metadata widgets), `audit` (audit-trail) |
| `ComplaintDetail` | `overview` (data + metadata), `audit` |

The three case-relation tabs ship as **stub Vue files** in
`src/components/tabs/` rendering a `CnNoteCard` placeholder with a TODO
comment. Full implementation deferred — the original CaseDetail page
had its tab content inlined; this change does not port that content.
Tracked under "Cleanup follow-up" below.

## Custom-fallback inventory

### Genuine exceptions (lib-fit issue, not migration cost)

- **`MyWork`** — bespoke 4-tab filter UI, mixed-entity list with type
  badges, completed toggle. Doesn't fit `index`.
- **`Werkvoorraad`** — KPI-strip-driven work queue with click-to-filter.
- **`CaseMap`** — Leaflet map view with WMS/WFS layers, marker clusters,
  draw tooling.
- **`PublicCase` / `PublicAppointment` / `PublicStatus`** — anonymous
  public routes.

### Lib gaps (could migrate if the lib were richer)

- **`Settings` (AdminRoot)** — would map to `type: "settings"` once the
  lib's `widgets[]` rich-section supports a custom-component slot.
  AdminRoot hosts `WorkflowEditor` (Vue Flow editor), `CaseTypeAdmin`
  (full CRUD with custom property editor), `ParafeerRouteAdmin`,
  `PartnerAdmin`, `MapLayerSettings`, `ZgwMappingSettings` — each
  too complex for a simple field/widget shape.
- **`Doorlooptijd`** — KPI dashboard with apexcharts. Migrating to
  `dashboard` is blocked on a `chart` widget primitive in the lib.

### Migration cost (acceptable to defer)

- **`Voorstellen` / `VoorstelDetail`** — list+detail pair tied to
  parafeerroute approver workflow. Migrating list to `index` is
  straightforward but would lose the bespoke status-tabs filter; defer
  to `procest-voorstel-v2`.

## Files affected

**New:**
- `procest/src/manifest.json`
- `procest/src/customComponents.js`
- `procest/tests/validate-manifest.js`
- `procest/l10n/en_US.json`
- `procest/src/components/tabs/CaseTasksTab.vue` (stub)
- `procest/src/components/tabs/CaseDecisionsTab.vue` (stub)
- `procest/src/components/tabs/CaseDocumentsTab.vue` (stub)

**Modified:**
- `procest/src/main.js`
- `procest/src/App.vue`
- `procest/webpack.config.js`
- `procest/package.json`
- `procest/appinfo/info.xml`
- `procest/openspec/changes/procest-manifest-v1/{proposal,design,tasks}.md`
- `procest/openspec/changes/procest-manifest-v1/specs/procest-manifest-v1/spec.md`

**Deleted:**
- `procest/src/views/Dashboard.vue` (→ `type: "dashboard"`)
- `procest/src/views/cases/CaseList.vue` (→ `type: "index"`)
- `procest/src/views/cases/CaseDetail.vue` (→ `type: "detail"`)
- `procest/src/views/tasks/TaskList.vue` (→ `type: "index"`)
- `procest/src/views/tasks/TaskDetail.vue` (→ `type: "detail"`)
- `procest/src/views/complaints/ComplaintList.vue` (→ `type: "index"`)
- `procest/src/views/complaints/ComplaintDetail.vue` (→ `type: "detail"`)
- `procest/src/router/index.js` (folded into `main.js`)
- `procest/src/navigation/MainMenu.vue` (replaced by `CnAppNav`)

**Surviving as customComponents:**
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

## Cleanup follow-up

Items not addressed in this change, tracked for a follow-up:

1. **Sidebar-tab implementations** — `CaseTasksTab`, `CaseDecisionsTab`,
   `CaseDecisionsTab`, `CaseDocumentsTab` ship as stubs rendering
   `CnNoteCard`. The current `CaseDetail.vue` had inline rendering of
   tasks / decisions / documents that needs porting. Follow-up:
   `procest-case-relation-tabs`.

2. **Voorstellen migration to `index`/`detail`** — see "Migration cost"
   above. Follow-up: `procest-voorstel-v2`.

3. **Doorlooptijd → dashboard.** Blocked on a lib-side `chart` widget.
   Follow-up: track against `nextcloud-vue/dashboard-chart-widget`.

4. **Settings → `type: "settings"`** — blocked on a custom-component
   slot inside `widgets[]` rich sections. Follow-up: track against
   `nextcloud-vue/settings-custom-component-slot`.

5. **CaseDetail bespoke logic** — parent-case breadcrumb, custom save
   handler with parafeerroute action injection. The abstract `detail`
   page handles save through `useObjectStore`; custom save logic must
   move into a custom save hook (lib gap) or stay in a per-page custom
   override. Tracked here.

6. **Public-route shell isolation.** If `CnAppRoot` doesn't gracefully
   handle unauthenticated context for the three Public* pages, split
   them out into a separate webpack entry with their own minimal mount
   point in a follow-up.

## Citations

- Decidesk PR (canonical reference): https://github.com/ConductionNL/decidesk/pull/160
- Decidesk source-of-truth: `decidesk/openspec/changes/decidesk-manifest-v1/`
- Library schema: `node_modules/@conduction/nextcloud-vue/src/schemas/app-manifest.schema.json` v1.2.0
- Library version: `@conduction/nextcloud-vue@1.0.0-beta.12`
- Cross-app convention: `hydra/openspec/architecture/adr-024-app-manifest.md`
- Bootstrap pattern: decidesk commit `50e4df7c5768b1025e4c7193d0f25943f8828e72`

## Open questions

1. **Dashboard widget normalisation.** The manifest declares 11 widgets
   all `type: "custom"`. The renderer's dashboard page must support
   per-widget `type: "custom"` resolution against the customComponents
   registry. If it doesn't (only schema-bound built-in widgets), Dashboard
   downgrades to `type: "custom"` and the existing `Dashboard.vue` stays
   in the registry. Default: optimistic migration; downgrade if
   validate-manifest or runtime breaks.

2. **CaseDetail save handler.** The current `CaseDetail.vue` implements
   bespoke save logic that injects parafeerroute actions on save.
   `CnDetailPage` runs save through the lib's `useObjectStore.save()`
   action. If parafeerroute logic can't move into an `@before-save`
   hook on `CnDetailPage`, CaseDetail downgrades to `type: "custom"`.

3. **Public route auth context.** The three `/public/...` routes mount
   under `CnAppRoot`, which expects an authenticated user. If runtime
   shows the public pages need the appshell removed, those routes get
   their own webpack entry / mount.
