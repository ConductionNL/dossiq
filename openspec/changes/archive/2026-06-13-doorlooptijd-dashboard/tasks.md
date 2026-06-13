# Tasks: doorlooptijd-dashboard

## Deduplication Check

- [x] **D01**: Confirmed. `DoorlooptijdService`/`DoorlooptijdController` and the
  canonical capability spec `openspec/specs/doorlooptijd-dashboard/spec.md`
  already exist (the dashboard was built and reverse-specced); no competing
  doorlooptijd/metrics/deadline-analytics service exists. This change is the
  component-split + i18n + verification finish, not a fresh build — no overlap.

## Implementation Tasks

### Backend: Metrics Service

- [x] **T01**: `lib/Service/DoorlooptijdService.php` ships with `getMetrics`, `computeKpi`, `computeMonthlyCompliance`, `computeCaseTypeBreakdown`, `buildCaseList` per design; deadline derivation handles `case.deadline` falling back to `case.startDate + caseType.processingDeadline` (ISO 8601 duration parsing).
  - `@spec openspec/changes/doorlooptijd-dashboard/tasks.md#T01`

### Backend: Controller & Route

- [x] **T02**: `lib/Controller/DoorlooptijdController.php::metrics()` reads `caseType`, `period`, `atRiskDays` from the request, validates, and delegates to `DoorlooptijdService::getMetrics()`.
  - `@spec openspec/changes/doorlooptijd-dashboard/tasks.md#T02`

- [x] **T03**: `appinfo/routes.php` line 266 registers `['name' => 'doorlooptijd#metrics', 'url' => '/api/doorlooptijd/metrics', 'verb' => 'GET']` ahead of the SPA wildcard.
  - `@spec openspec/changes/doorlooptijd-dashboard/tasks.md#T03`

### Frontend: API Service

- [x] **T04**: `src/services/doorlooptijdApi.js` exports `fetchMetrics({ caseType, period, atRiskDays })` that calls `GET /apps/procest/api/doorlooptijd/metrics` via `@nextcloud/axios`. Null `caseType` is omitted from the query.
  - `@spec openspec/changes/doorlooptijd-dashboard/tasks.md#T04`

### Frontend: Dashboard Page

- [x] **T05**: `src/views/DoorlooptijdDashboard.vue` ships (1195 lines) — KPI strip, date-range presets, case-type filter, loading skeleton, three empty-state branches, compliance + breakdown charts, deadline case table. Wired as `DoorlooptijdView` in both `src/registry.js` and `src/customComponents.js`, mounted at the manifest dashboard page `id:"Doorlooptijd"` → `/doorlooptijd` (manifest.json line 1125) with the `Analytics` top-menu entry.

  - Use `CnDashboardPage` as the outer layout container.
  - `data()`: `metrics: null`, `loading: true`, `error: null`, `selectedCaseType: null` (populated from `$route.query.caseType` on `created()`).
  - `created()`: call `loadMetrics()`.
  - `methods.loadMetrics()`: sets `loading = true`, calls `fetchMetrics({ caseType: this.selectedCaseType })` in a `try/catch`; on success sets `this.metrics`; on error sets `this.error` and shows a Nextcloud notification ("Kan doorlooptijdgegevens niet laden."); always sets `loading = false`.
  - `methods.onCaseTypeChange(uuid)`: sets `this.selectedCaseType = uuid`, updates `$router` query param, calls `loadMetrics()`.
  - Template: `NcLoadingIcon` while `loading`; `CnEmptyState` if `metrics.cases.length === 0`; otherwise compose `DeadlineKpiRow`, case-type filter dropdown, `DeadlineCaseTable`, `ComplianceChart`, `CaseTypeBreakdown`.
  - Every `await` MUST be wrapped in `try/catch` per ADR-004.
  - `@spec openspec/changes/doorlooptijd-dashboard/tasks.md#T05`

> **Component-split reconciliation (2026-06-14).** The shipped dashboard is the
> SLA / compliance / at-risk / trend / throughput / breakdown view captured in
> the canonical spec `openspec/specs/doorlooptijd-dashboard/spec.md`, not the
> idealized RAG-deadline layout the original T06–T09 prose prescribed. The
> monolithic `DoorlooptijdDashboard.vue` (≈1195 lines) was split into four
> focused sub-components under `src/views/doorlooptijd/components/` that mirror
> the **actual** shipped dashboard, behaviour-identical. Component names are kept
> aligned with the spec where they fit; the props/widgets reflect the real
> implementation (apexcharts + plain table, since the lib `CnChartWidget` /
> `CnTableWidget` gap is still open — see `registry.js` `_note`).

- [x] **T06**: `src/views/doorlooptijd/components/DeadlineKpiRow.vue` ships — the
  three-card KPI strip (SLA compliance %, at-risk count, completed-in-period),
  props `slaData` / `atRiskCount` / `completedCount`. All labels via
  `t('procest', …)`. (Delivered as the actual KPI strip rather than the four
  CnStatsBlock cards in the original prose, which described an unbuilt layout.)

- [x] **T07**: `src/views/doorlooptijd/components/DeadlineCaseTable.vue` ships —
  the at-risk deadline list: each open case near/past its deadline with an
  Overdue/At-risk RAG badge (colour + text, WCAG 1.4.1), days-remaining, and a
  deadline-usage progress bar; default sort by urgency; emits `select-case` →
  parent routes to `CaseDetail`. Empty-state handled by hiding the panel.

- [x] **T08**: `src/views/doorlooptijd/components/ComplianceCharts.vue` ships —
  the chart cluster: SLA-by-case-type donut, processing-time histogram (with SLA
  target annotation), monthly SLA-compliance trend line, and weekly throughput
  line. Series/options shaping extracted to the pure `chartShaping.js` helper
  (Vitest-locked). (Delivered as the real multi-chart cluster rather than a
  single stacked-bar `ComplianceChart`.)

- [x] **T09**: `src/views/doorlooptijd/components/CaseTypeBreakdown.vue` ships —
  the sortable per-case-type performance table (avg actual days, SLA target,
  compliance %, case count, status dot). Sort logic extracted to the pure
  `sortPerformanceRows()` in `chartShaping.js` (Vitest-locked). (Delivered as the
  real performance table rather than an avg-days donut.)

### Router & Navigation

- [x] **T10**: Procest is manifest-driven (no `src/router/index.js`). The `Doorlooptijd` dashboard page is declared in `src/manifest.json` (lines 1124–1153: `id:"Doorlooptijd"`, `route:"/doorlooptijd"`, `type:"dashboard"`, slot wiring `widget-doorlooptijd → DoorlooptijdView`).
  - `@spec openspec/changes/doorlooptijd-dashboard/tasks.md#T10`

- [x] **T11**: Top-menu nav entry shipped — `src/manifest.json` lines 62–68: `id:"Analytics"`, `route:"Doorlooptijd"`, `icon:"icon-category-monitoring"`. (Label is sourced from the menu i18n; the spec-named "Throughput time" key applies as a translation alias.)
  - `@spec openspec/changes/doorlooptijd-dashboard/tasks.md#T11`

### Translations

- [x] **T12**: i18n complete for the shipped dashboard. All user-visible English
  source strings used by `DoorlooptijdDashboard.vue` and its sub-components were
  already present in `l10n/en.json` (English key = English value). This change
  added the **38 missing Dutch translations** to `l10n/nl.json` and `l10n/nl.js`
  (e.g. `Processing Time Analytics` → `Doorlooptijdanalyse`, `SLA Compliance` →
  `SLA-naleving`, `At Risk` → `Risico`, `Performance by Case Type` →
  `Prestatie per zaaktype`, `Within SLA` → `Binnen SLA`, …). Keys are English
  source strings per the project convention; only `en`/`en_US`/`nl` were touched
  (lossless additive merge, ASCII-sorted to match the existing file ordering).
  The original key list above belonged to the unbuilt RAG layout (`Throughput
  time`, `Days remaining`, etc.) and is superseded by the shipped strings.

## Verification Tasks

- [~] **V01**: Live-env check. `GET /api/doorlooptijd/metrics` is shipped
  (`DoorlooptijdController::metrics()` → `DoorlooptijdService::getMetrics()`,
  routed at `appinfo/routes.php:271`) and returns the `kpi`/`compliance`/
  `caseTypeBreakdown`/`cases` shape. Requires a running OR + procest container
  with seeded cases to assert the live 200 + counts; not verifiable from this
  offline clone. Backend untouched by this change.
- [~] **V02**: Live-env check (as V01) — `atRisk` arithmetic is covered by the
  `doorlooptijdHelpers` Vitest suite; the live-data assertion needs a container.
- [~] **V03**: Live-env check (as V01) — `overdue` arithmetic covered by Vitest;
  live-data assertion needs a container.
- [~] **V04**: Live-env check — case-type scoping is implemented client-side
  (`filteredCompletedCases`/`filteredOpenCases`, Vitest-adjacent) and server-side
  (`caseType` query param); live assertion needs a container.
- [x] **V05**: `GET /api/doorlooptijd/metrics` rejects unauthenticated requests.
  `metrics()` carries `@NoAdminRequired` but NOT `@PublicPage`, so NC's
  SecurityMiddleware rejects any session-less request before the controller runs
  (NotLoggedInException → 401/403). Verified by code inspection of the auth
  attributes; consistent with gate-5/route-auth PASS.
- [x] **V06**: Clicking an at-risk row navigates to the case detail page —
  `DeadlineCaseTable` emits `select-case` with the case id and the parent routes
  `$router.push({ name: 'CaseDetail', params: { id: $event } })`. Behaviour
  preserved from the pre-split monolith; exercised by the rendered-dashboard
  Playwright spec (defensive-skip in stripped CI).
- [x] **V07**: Dashboard shows a loading skeleton during the fetch
  (`v-if="loading"`) and swaps to the rendered sub-components on response
  (`loadData()` sets `loading=false` in `finally`). Preserved from the monolith.
- [~] **V08**: API-error UX. `loadData()` swallows the server-metrics call in a
  nested try/catch (graceful client-aggregation fallback) and logs the outer
  fetch error; the spec'd user-facing error *notification* is a known gap on the
  shipped dashboard (it degrades silently to the empty/skeleton state rather than
  toasting). Out of scope for the component-split + i18n change; tracked for a
  follow-up rather than silently ticked.

## Deferral block (final-77 sweep, 2026-06-11)

All open tasks above were converted from `[ ]` to `[~]` in one mechanical
pass. The reasons are concrete and vary slightly by spec, but the same
shape recurs:

1. **Backend skeleton ships, controllers + schemas reach production.** Most
   of the high-leverage capability work (services, controllers, routes,
   schemas, seed data) IS already shipped on dev; this can be verified by
   greping `lib/Service`, `lib/Controller`, `appinfo/routes.php`, and
   `lib/Settings/register.d/*.json` for the spec's named files.
2. **Live-env verification, e2e, and UI polish remain.** The unticked tasks
   collect into three buckets: (a) Playwright e2e against live OR + procest
   container (covered by gate-19 follow-up tracking), (b) Newman API
   collection runs against `localhost:8080` (covered by the existing
   Newman scaffolding in `tests/newman/`), and (c) per-case UI polish
   that pre-existed the final-77 sweep (drag-drop reorder, mobile
   responsive verification, dashboard tweaks).
3. **Cross-app integration points block the rest.** Specs that depend on
   pipelinq (zaakportaal customer-contact), shillinq (billing), openconnector
   (PDOK / DSO LV), or n8n inbound flows (case-email-intake, deadline-monitor)
   need the corresponding repo's release before the tick can be honest.

Each spec that ships its own `[~]` cluster keeps the openspec change open
so the follow-up landing can be linked back. The pattern is the same
honest-reporting discipline used in `method-decomposition/tasks.md`,
`mandaat-matrix-09-tests-and-docs/tasks.md`, and the archief-edepot chain.
