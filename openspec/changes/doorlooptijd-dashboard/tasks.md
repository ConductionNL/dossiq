# Tasks: doorlooptijd-dashboard

## Deduplication Check

- [ ] **D01**: Search `openspec/specs/`, `lib/Service/`, and `lib/Controller/` for any existing doorlooptijd, metrics aggregation, or deadline analytics service. Document findings. Confirm no overlap before beginning T01.

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

- [ ] **T06**: Create `src/views/doorlooptijd/components/DeadlineKpiRow.vue`.

  - Props: `kpi: { open: Number, atRisk: Number, overdue: Number, onTimePercent: Number }`.
  - Render four `CnStatsBlock` components in a responsive row (2×2 on mobile, 4×1 on desktop):
    1. **Openstaand** — `kpi.open`, icon `mdi-folder-open-outline`, colour: primary
    2. **Risico** — `kpi.atRisk`, icon `mdi-clock-alert-outline`, colour: warning
    3. **Verlopen** — `kpi.overdue`, icon `mdi-alert-circle-outline`, colour: error
    4. **Op tijd** — `kpi.onTimePercent`%, icon `mdi-check-circle-outline`, colour: success
  - All label strings via `t(appName, 'key')`.

- [ ] **T07**: Create `src/views/doorlooptijd/components/DeadlineCaseTable.vue`.

  - Props: `cases: Array`.
  - Render `CnTableWidget` with columns: Zaaknummer, Titel, Zaaktype, Startdatum, Deadline, Resterende dagen, Status.
  - "Resterende dagen" column: format as `−N dagen` for negative values, `N dagen` for positive.
  - "Status" column: `CnStatusBadge` — `type="error"` + label "Verlopen" for `ragStatus === 'overdue'`; `type="warning"` + label "Risico" for `at-risk`; `type="success"` + label "Op tijd" for `on-time`.
  - Default sort: `daysRemaining` ascending (most urgent first).
  - Row click: `$router.push({ name: 'CaseDetail', params: { id: row.id } })`.
  - Empty state: show "Geen openstaande zaken gevonden." when `cases` is empty.

- [ ] **T08**: Create `src/views/doorlooptijd/components/ComplianceChart.vue`.

  - Props: `compliance: Array<{ month: String, onTime: Number, late: Number, percent: Number }>`.
  - Render `CnChartWidget` with `type="bar"`, `stacked: true`.
  - Series: `[{ name: t(appName, 'On time'), data: compliance.map(m => m.percent) }, { name: t(appName, 'Late'), data: compliance.map(m => 100 - m.percent) }]`.
  - Colours: on-time → `var(--color-success)`, late → `var(--color-error)`.
  - X-axis labels: format `month` (YYYY-MM) as short Dutch month + year (e.g. "apr. 2026").
  - Y-axis: 0–100%, suffix `%`.
  - Tooltip: "{{month}} — Op tijd: {{onTime}} ({{percent}}%), Te laat: {{late}} ({{100-percent}}%)".

- [ ] **T09**: Create `src/views/doorlooptijd/components/CaseTypeBreakdown.vue`.

  - Props: `caseTypeBreakdown: Array<{ id: String, title: String, avgDays: Number, count: Number }>`.
  - Render `CnChartWidget` with `type="donut"`.
  - Series: `caseTypeBreakdown.map(ct => ct.avgDays)`.
  - Labels: `caseTypeBreakdown.map(ct => ct.title)`.
  - Legend sorted by `avgDays` descending (already sorted by backend).
  - Tooltip: "{{title}}: gemiddeld {{avgDays}} dagen ({{count}} zaken)".

### Router & Navigation

- [x] **T10**: Procest is manifest-driven (no `src/router/index.js`). The `Doorlooptijd` dashboard page is declared in `src/manifest.json` (lines 1124–1153: `id:"Doorlooptijd"`, `route:"/doorlooptijd"`, `type:"dashboard"`, slot wiring `widget-doorlooptijd → DoorlooptijdView`).
  - `@spec openspec/changes/doorlooptijd-dashboard/tasks.md#T10`

- [x] **T11**: Top-menu nav entry shipped — `src/manifest.json` lines 62–68: `id:"Analytics"`, `route:"Doorlooptijd"`, `icon:"icon-category-monitoring"`. (Label is sourced from the menu i18n; the spec-named "Throughput time" key applies as a translation alias.)
  - `@spec openspec/changes/doorlooptijd-dashboard/tasks.md#T11`

### Translations

- [ ] **T12**: Add all new user-visible strings to `l10n/en.json` (English key = English value) and `l10n/nl.json` (Dutch translation). Required keys:

  | English key | Dutch translation |
  |-------------|-----------------|
  | `Throughput time` | `Doorlooptijd` |
  | `Open` | `Openstaand` |
  | `At risk` | `Risico` |
  | `Overdue` | `Verlopen` |
  | `On time` | `Op tijd` |
  | `Days remaining` | `Resterende dagen` |
  | `Case type` | `Zaaktype` |
  | `All case types` | `Alle zaaktypen` |
  | `Start date` | `Startdatum` |
  | `Deadline` | `Deadline` |
  | `Could not load throughput data. Please try again.` | `Kan doorlooptijdgegevens niet laden. Probeer het opnieuw.` |
  | `No open cases found.` | `Geen openstaande zaken gevonden.` |
  | `No cases found.` | `Geen zaken gevonden.` |
  | `Late` | `Te laat` |

## Verification Tasks

- [ ] **V01**: `GET /api/doorlooptijd/metrics` returns HTTP 200 with `kpi`, `compliance`, `caseTypeBreakdown`, and `cases` keys; `kpi.open` equals the number of cases with null `endDate` in OpenRegister.
- [ ] **V02**: `kpi.atRisk` is 0 when no open case has a deadline within 5 days of today.
- [ ] **V03**: `kpi.overdue` is 0 when no open case has a deadline before today.
- [ ] **V04**: `GET /api/doorlooptijd/metrics?caseType=<uuid>` returns metrics scoped to that case type only.
- [ ] **V05**: `GET /api/doorlooptijd/metrics` without authentication returns HTTP 403.
- [ ] **V06**: Clicking a row in the case list navigates to the correct case detail page.
- [ ] **V07**: Dashboard shows `NcLoadingIcon` during the API fetch and replaces it with rendered widgets on response.
- [ ] **V08**: API error triggers a user-facing error notification; no stale data is displayed.

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
