# Proposal: process-mining-bottlenecks

kind: code — new capability. Adds a process-mining bottleneck report — per-
status dwell-time stats, a bottleneck ranking, a transition frequency matrix
with rework-loop detection, and a weekly throughput trend — computed from the
`statusRecord` history procest already writes on every case status
transition, surfaced as a coordinator-only dashboard.

## Why

Process mining research shows 40–60% improvement potential in municipal case
handling by finding where cases actually get stuck — but procest already
captures the raw material (per-status timestamps via `statusRecord`,
doorlooptijd/termijn data) and offers no bottleneck analysis. Coordinators
can currently only see aggregate throughput-time compliance
(`DoorlooptijdService`) or a single case's own history (`transition-history`
tab) — nothing surfaces *which status* is the actual bottleneck across the
case population, nor whether cases are looping back through statuses they've
already left (rework).

**Verified against `origin/development` HEAD before designing this change**
(no parallel event log invented):

- `StatusTransitionService::execute()`/`executeFreeForm()` write one
  `statusRecord` object per transition — fields `case`, `statusType` (the
  target status), `fromStatus` (the prior status, when present),
  `transitionLabel`, `description`, plus OpenRegister's own creation
  timestamp (`createdAt`, or `@self.created` depending on serialisation
  path). `StatusTransitionService::replay()` already reads this chain back
  per case, sorted chronologically — the source of truth for status-change
  history (`lib/Service/StatusTransitionService.php`).
- `retire-status-history-page` (archived 2026-06/07) confirms
  `statusRecord` objects are the canonical, permanent record of case status
  history — the standalone page was retired, but the underlying OpenRegister
  data and the per-case "Change history" sidebar tab stayed. This change
  reads the same register at population scale rather than inventing a
  second event log.
- `DoorlooptijdService` is the closest sibling for "compute derived metrics
  from `case` via `SearchesObjects` + `ObjectService::searchObjects()`, no
  raw SQL" — followed here for the same reason (`KpiAggregationService`'s
  raw-SQL `JSON_EXTRACT` approach does not fit a `statusRecord`-chain replay
  across the whole case population as cleanly).
- `Iv3ReportController` (`openspec/changes/archive/2026-07-13-iv3-case-cost-reporting`)
  is the closest controller precedent: a coordinator-only, tenant-wide
  aggregation report (not "my own work"), gated via `IGroupManager`
  allow-list (`controllers`/`beheerders`/`admin`) with an `isAdmin()`
  fallback — reused verbatim here (`ALLOWED_GROUPS` + `ensureAllowed()` +
  `isAllowed()`), rather than `DoorlooptijdController`'s plain
  "any authenticated user" gate, since a process-mining report spans every
  case in the tenant.
- `Iv3ReportDashboard.vue` / `TermijnDashboard.vue` are the closest frontend
  precedents: `type: "custom"` manifest page + `component` field (not the
  older `type: "dashboard"` + widget/slots indirection `DoorlooptijdDashboard`
  uses), registered in `customComponents.js` + `menu-layout.json`, added to
  the existing `AnalyticsGroup` ("Reports") nav section.

Per ADR-Leaf-First, procest ships the DATA PROVIDER (service + controller)
and page config only; every visualisation is an existing nc-vue leaf
(`CnKpiGrid`, `CnStatsBlock`, `CnChartWidget`) — no new chart component is
built.

## What Changes

- **NEW**: `lib/Service/ProcessMiningService.php` — pure, unit-testable
  computation (dwell intervals, dwell-stat aggregation, bottleneck ranking,
  transition matrix + rework detection, weekly throughput trend), separated
  from the single OpenRegister read path (`SearchesObjects` trait).
- **NEW**: `lib/Controller/ProcessMiningController.php` — `GET
  /api/reports/process-mining?from=&to=&caseType=`, gated to
  `controllers`/`beheerders`/`admin` (same shape as `Iv3ReportController`).
- **UI**: `src/views/dashboard/ProcessMiningDashboard.vue` — period preset +
  case-type picker, `CnKpiGrid`/`CnStatsBlock` KPI tiles (cases analysed,
  case types, overall rework rate, top bottleneck), `CnChartWidget` dwell-time
  bar chart + throughput line chart, a plain bottleneck-ranking table (same
  hand-rolled-table convention `TermijnDashboard.vue`/`Iv3ReportDashboard.vue`
  use for ad-hoc computed rows — no nc-vue table leaf fits that shape), a
  rework-rate callout. Added to `AnalyticsGroup` via `customComponents.js` +
  `manifest.json` + `menu-layout.json`.
- **Tests**: PHPUnit for dwell-time math (open/closed cases, zero-duration,
  missing history, period-window clamping), bottleneck ranking, rework-loop
  detection, transition-matrix aggregation, throughput bucketing, controller
  auth (401/403/200) and error translation; Vitest for the pure chart/table
  shaping helpers (`processMiningShaping.js`).
- **Fix (pre-existing)**: the shared `FakeTermijnStore` PHPUnit fixture
  (`tests/Unit/Fixtures/FakeTermijnStore.php`) didn't strip OpenRegister's
  `_limit`/`_offset` pagination keys before applying its equality filter,
  so any service under test that paginates (as `ProcessMiningService` does)
  would silently get zero rows back. Fixed to match the real
  `SearchesObjects` bridge's documented behaviour; the existing suites that
  don't pass `_limit` are unaffected.

## Impact

- Affected specs: `process-mining-bottlenecks` (new capability spec).
- Affected code: `lib/Service/ProcessMiningService.php` (new),
  `lib/Controller/ProcessMiningController.php` (new), `appinfo/routes.php`
  (additive), `src/views/dashboard/ProcessMiningDashboard.vue` (new),
  `src/views/processMining/processMiningShaping.js` (new),
  `src/services/processMiningApi.js` (new), `src/customComponents.js`,
  `src/manifest.json`, `src/menu-layout.json`,
  `tests/Unit/Fixtures/FakeTermijnStore.php` (bugfix).
- No new Composer or npm dependencies. No schema/register changes — reads
  the existing `case`, `caseType`, `statusType`, `statusRecord` schemas
  only. No changes to OpenRegister, OpenCatalogi, or Pipelinq.
