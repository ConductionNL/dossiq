# Refactor Procest IA Alignment

## Why

A fresh Information Architecture (IA) proposal was drafted for procest based on
user-journey analysis and competitive-IA review. This change reconciles the
fleet-wide IA proposal against what is actually implemented in
`apps-extra/procest/src/manifest.json` and the Vue view tree.

The audit covered 14 implemented specs. **13 are already aligned**; **1
drifts** and is moved by this change. No new specs are introduced — only
re-placements.

## What Changes

### Drifted spec

- **`task-management`** — currently a TOP_MENU entry (`/tasks`, manifest
  menu order 50). Proposed IA placement is `Mijn werk › Taken`. The Tasks
  list belongs nested under My Work, not as a sibling top-level item, because
  the only journey that lands on the global task list IS "what's on my plate"
  — which is My Work's job. A separate top-level Tasks page duplicates the
  framing and pushes case-scoped task views (already correctly modelled as a
  `CaseTasksTab` sidebar tab on `CaseDetail`) into a less-discoverable second
  surface.

  **Move:** demote the top-level `Tasks` menu entry to a sub-route under My
  Work. The `/tasks` route stays (deep links + `CaseTasksTab` navigation), but
  the left-nav exposes it as a child of `MyWork` rather than as a sibling.

### Verified aligned (no change)

| spec                          | IA placement                                       | current placement                          |
|-------------------------------|----------------------------------------------------|--------------------------------------------|
| `admin-settings`              | Configuratie › Admin                               | `section: settings` drawer (multiple entries) |
| `case-dashboard-view`         | WIDGET on Dashboard                                | `count-open-cases`, `cases-by-status`, `cases-by-type`, `count-overdue`, `count-completed` widgets in Dashboard manifest |
| `case-management`             | Zaken › Alle zaken                                 | top-level `Cases` (`/cases`) — IS the "Alle zaken" surface |
| `case-types`                  | Configuratie › Zaaktypes (CONFIG, geen menu)       | `CaseTypesMenu` in settings drawer         |
| `dashboard`                   | TOP_MENU                                            | top-level `Dashboard` (`/`)                |
| `my-work`                     | TOP_MENU                                            | top-level `MyWork` (`/my-work`)            |
| `openregister-integration`    | SETTING › Configuratie › Integraties               | foundational infra; no UI route to misplace |
| `procest-app-scaffold`        | meta / —                                            | scaffold-only; no UI                       |
| `procest-case-management`     | Zaken › Alle zaken                                 | implementation spec for case-management; same placement |
| `procest-object-store`        | SETTING › Configuratie › Admin › Storage           | runtime Pinia store; no UI                 |
| `prometheus-metrics`          | SETTING › Configuratie › Admin › Observability     | backend `/api/metrics` endpoint; no UI     |
| `roles-decisions`             | SETTING › Configuratie › Rollen & rechten          | role/decision data via `CaseDecisionsTab` sidebar tab; TYPE config lives under Case Types admin. No separate top-level routes. |
| `zgw-api-mapping`             | SETTING › Configuratie › Integraties               | backend ZGW endpoints; no UI route         |

## Impact

- **Affected specs**: `task-management` (placement change only — data model and
  REQ-TASK-* requirements unchanged).
- **Affected code**:
  - `src/manifest.json` — `Tasks` menu entry demoted (parent/group under
    MyWork, or removed from top-level and surfaced from within MyWork).
  - `src/views/MyWork.vue` — adds explicit "Taken" tab/link to `/tasks` (the
    existing My Work view already aggregates personal cases + tasks; this
    surfaces the global task list explicitly).
  - No backend changes. No schema changes. Routes preserved for deep-link
    backwards compatibility.
- **Risk**: low — purely menu structure; route paths unchanged.
