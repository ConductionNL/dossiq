# Tasks: doorlooptijd-dashboard

## Deduplication Check

- [~] **D01**: Search `openspec/specs/`, `lib/Service/`, and `lib/Controller/` for any existing doorlooptijd, metrics aggregation, or deadline analytics service. Document findings. Confirm no overlap before beginning T01. — deferred to downstream cycle / fleet-wide adoption (handoff)

## Implementation Tasks

### Backend: Metrics Service

- [~] **T01**: Create `lib/Service/DoorlooptijdService.php`. — deferred to downstream cycle / fleet-wide adoption (handoff)

  Public methods (all annotate with `@spec openspec/changes/doorlooptijd-dashboard/tasks.md#T01`):
  - `getMetrics(array $params): array` — main entry point; accepts `caseType` (UUID|null), `period` (string, default `12m`), `atRiskDays` (int, default 5); calls the four helpers below and merges results.
  - `computeKpi(array $cases, int $atRiskDays): array` — returns `{ open, atRisk, overdue, onTimePercent }`. Open = `endDate` null. At-risk = open with `0 ≤ daysRemaining ≤ atRiskDays`. Overdue = open with `daysRemaining < 0`. On-time % = closed cases (last 12 months) where `endDate ≤ deadline` / total closed × 100.
  - `computeMonthlyCompliance(array $cases, string $period): array` — returns an array of `{ month (YYYY-MM), onTime, late, percent }` for each of the last N calendar months (N derived from `$period`).
  - `computeCaseTypeBreakdown(array $cases): array` — returns `[{ id, title, avgDays, count }]` for case types with at least one closed case, sorted by `avgDays` descending.
  - `buildCaseList(array $cases, int $atRiskDays): array` — returns all open cases sorted by `daysRemaining` ASC, each with `{ id, identifier, title, caseTypeTitle, startDate, deadline, daysRemaining, ragStatus }` where `ragStatus` is `overdue` | `at-risk` | `on-time`.

  Deadline derivation: if `case.deadline` is null, compute as `case.startDate + caseType.processingDeadline` (ISO 8601 duration parsing). Cases with neither field: exclude from deadline metrics, include in doorlooptijd averages (if closed).

### Backend: Controller & Route

- [~] **T02**: Create `lib/Controller/DoorlooptijdController.php`. — deferred to downstream cycle / fleet-wide adoption (handoff)

  - `metrics(IRequest $request): JSONResponse` — reads `caseType`, `period`, `atRiskDays` from query params; validates types; delegates to `DoorlooptijdService::getMetrics()`; returns JSON.
  - Return 400 with `{ message }` for invalid parameter values (e.g. non-integer `atRiskDays`).
  - Annotate with `@spec openspec/changes/doorlooptijd-dashboard/tasks.md#T02`.

- [~] **T03**: Add route to `appinfo/routes.php`: — deferred to downstream cycle / fleet-wide adoption (handoff)
  ```php
  ['name' => 'doorlooptijd#metrics', 'url' => '/api/doorlooptijd/metrics', 'verb' => 'GET'],
  ```
  Place this route before any wildcard `{slug}` routes per ADR-003.

### Frontend: API Service

- [~] **T04**: Create `src/services/doorlooptijdApi.js`. — deferred to downstream cycle / fleet-wide adoption (handoff)

  Export a single function `fetchMetrics({ caseType = null, period = '12m', atRiskDays = 5 } = {})` that calls `GET /api/doorlooptijd/metrics` via `@nextcloud/axios` with the provided query params (omit null params). Return the response data object.

### Frontend: Dashboard Page

- [~] **T05**: Create `src/views/doorlooptijd/DoorlooptijdDashboard.vue`. — deferred to downstream cycle / fleet-wide adoption (handoff)

  - Use `CnDashboardPage` as the outer layout container.
  - `data()`: `metrics: null`, `loading: true`, `error: null`, `selectedCaseType: null` (populated from `$route.query.caseType` on `created()`).
  - `created()`: call `loadMetrics()`.
  - `methods.loadMetrics()`: sets `loading = true`, calls `fetchMetrics({ caseType: this.selectedCaseType })` in a `try/catch`; on success sets `this.metrics`; on error sets `this.error` and shows a Nextcloud notification ("Kan doorlooptijdgegevens niet laden."); always sets `loading = false`.
  - `methods.onCaseTypeChange(uuid)`: sets `this.selectedCaseType = uuid`, updates `$router` query param, calls `loadMetrics()`.
  - Template: `NcLoadingIcon` while `loading`; `CnEmptyState` if `metrics.cases.length === 0`; otherwise compose `DeadlineKpiRow`, case-type filter dropdown, `DeadlineCaseTable`, `ComplianceChart`, `CaseTypeBreakdown`.
  - Every `await` MUST be wrapped in `try/catch` per ADR-004.

- [~] **T06**: Create `src/views/doorlooptijd/components/DeadlineKpiRow.vue`. — deferred to downstream cycle / fleet-wide adoption (handoff)

  - Props: `kpi: { open: Number, atRisk: Number, overdue: Number, onTimePercent: Number }`.
  - Render four `CnStatsBlock` components in a responsive row (2×2 on mobile, 4×1 on desktop):
    1. **Openstaand** — `kpi.open`, icon `mdi-folder-open-outline`, colour: primary
    2. **Risico** — `kpi.atRisk`, icon `mdi-clock-alert-outline`, colour: warning
    3. **Verlopen** — `kpi.overdue`, icon `mdi-alert-circle-outline`, colour: error
    4. **Op tijd** — `kpi.onTimePercent`%, icon `mdi-check-circle-outline`, colour: success
  - All label strings via `t(appName, 'key')`.

- [~] **T07**: Create `src/views/doorlooptijd/components/DeadlineCaseTable.vue`. — deferred to downstream cycle / fleet-wide adoption (handoff)

  - Props: `cases: Array`.
  - Render `CnTableWidget` with columns: Zaaknummer, Titel, Zaaktype, Startdatum, Deadline, Resterende dagen, Status.
  - "Resterende dagen" column: format as `−N dagen` for negative values, `N dagen` for positive.
  - "Status" column: `CnStatusBadge` — `type="error"` + label "Verlopen" for `ragStatus === 'overdue'`; `type="warning"` + label "Risico" for `at-risk`; `type="success"` + label "Op tijd" for `on-time`.
  - Default sort: `daysRemaining` ascending (most urgent first).
  - Row click: `$router.push({ name: 'CaseDetail', params: { id: row.id } })`.
  - Empty state: show "Geen openstaande zaken gevonden." when `cases` is empty.

- [~] **T08**: Create `src/views/doorlooptijd/components/ComplianceChart.vue`. — deferred to downstream cycle / fleet-wide adoption (handoff)

  - Props: `compliance: Array<{ month: String, onTime: Number, late: Number, percent: Number }>`.
  - Render `CnChartWidget` with `type="bar"`, `stacked: true`.
  - Series: `[{ name: t(appName, 'On time'), data: compliance.map(m => m.percent) }, { name: t(appName, 'Late'), data: compliance.map(m => 100 - m.percent) }]`.
  - Colours: on-time → `var(--color-success)`, late → `var(--color-error)`.
  - X-axis labels: format `month` (YYYY-MM) as short Dutch month + year (e.g. "apr. 2026").
  - Y-axis: 0–100%, suffix `%`.
  - Tooltip: "{{month}} — Op tijd: {{onTime}} ({{percent}}%), Te laat: {{late}} ({{100-percent}}%)".

- [~] **T09**: Create `src/views/doorlooptijd/components/CaseTypeBreakdown.vue`. — deferred to downstream cycle / fleet-wide adoption (handoff)

  - Props: `caseTypeBreakdown: Array<{ id: String, title: String, avgDays: Number, count: Number }>`.
  - Render `CnChartWidget` with `type="donut"`.
  - Series: `caseTypeBreakdown.map(ct => ct.avgDays)`.
  - Labels: `caseTypeBreakdown.map(ct => ct.title)`.
  - Legend sorted by `avgDays` descending (already sorted by backend).
  - Tooltip: "{{title}}: gemiddeld {{avgDays}} dagen ({{count}} zaken)".

### Router & Navigation

- [~] **T10**: Add route to `src/router/index.js`: — deferred to downstream cycle / fleet-wide adoption (handoff)
  ```js
  {
    path: '/doorlooptijd',
    name: 'DoorlooptijdDashboard',
    component: () => import('../views/doorlooptijd/DoorlooptijdDashboard.vue'),
  }
  ```

- [~] **T11**: Add "Doorlooptijd" `NcAppNavigationItem` to `src/views/MainMenu.vue`. — deferred to downstream cycle / fleet-wide adoption (handoff)
  - Icon: `mdi-clock-alert-outline`.
  - `:to="{ name: 'DoorlooptijdDashboard' }"`.
  - Label: `t(appName, 'Throughput time')`.

### Translations

- [~] **T12**: Add all new user-visible strings to `l10n/en.json` (English key = English value) and `l10n/nl.json` (Dutch translation). Required keys: — deferred to downstream cycle / fleet-wide adoption (handoff)

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

- [~] **V01**: `GET /api/doorlooptijd/metrics` returns HTTP 200 with `kpi`, `compliance`, `caseTypeBreakdown`, and `cases` keys; `kpi.open` equals the number of cases with null `endDate` in OpenRegister. — deferred to downstream cycle / fleet-wide adoption (handoff)
- [~] **V02**: `kpi.atRisk` is 0 when no open case has a deadline within 5 days of today. — deferred to downstream cycle / fleet-wide adoption (handoff)
- [~] **V03**: `kpi.overdue` is 0 when no open case has a deadline before today. — deferred to downstream cycle / fleet-wide adoption (handoff)
- [~] **V04**: `GET /api/doorlooptijd/metrics?caseType=<uuid>` returns metrics scoped to that case type only. — deferred to downstream cycle / fleet-wide adoption (handoff)
- [~] **V05**: `GET /api/doorlooptijd/metrics` without authentication returns HTTP 403. — deferred to downstream cycle / fleet-wide adoption (handoff)
- [~] **V06**: Clicking a row in the case list navigates to the correct case detail page. — deferred to downstream cycle / fleet-wide adoption (handoff)
- [~] **V07**: Dashboard shows `NcLoadingIcon` during the API fetch and replaces it with rendered widgets on response. — deferred to downstream cycle / fleet-wide adoption (handoff)
- [~] **V08**: API error triggers a user-facing error notification; no stale data is displayed. — deferred to downstream cycle / fleet-wide adoption (handoff)
