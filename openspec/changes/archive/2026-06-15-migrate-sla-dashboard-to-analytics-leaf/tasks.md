# Tasks: migrate-sla-dashboard-to-analytics-leaf

All tasks are in the `procest` repo. Estimates: S = half-day, M = 1–2 days, L = 3+ days.
Implemented through Hydra; the OR foundation (PR #151) is the prerequisite that unblocked it.

## [procest] Pre-migration Verification

### P0. Confirm analytics leaf contract (S)

- [x] P0.1 Confirmed the OR analytics-series leaf surface (OR PR #151): page-level (NOT object-sidebar)
  series render contract via `POST /api/integrations/analytics/series` (register/refresh:
  `{seriesKey, labels, datasets, title, chartType, visibility}`) + `GET .../series/{seriesKey}`
  (RBAC-scoped fetch). OR owns persistence + the chart-ready render contract + the page-widget
  declaration on the IntegrationRegistry; the leaf (procest) owns the maths. The chart is drawn by
  `@conduction/nextcloud-vue`'s declarative `CnChartWidget` (the lib owns the chart engine /
  apexcharts) — procest declares NO chart library of its own.
- [x] P0.2 GH-issue note: the optional ADR-031 unification (SLA target/compliance as a
  schema-declarative `x-openregister-calculations` derived field, so the analytics leaf reads a
  plain field) is recorded as a future follow-up in design.md. Out of scope for this change — the
  case-domain calc stays in `doorlooptijdHelpers.js` for now.

## [procest] Wire the leaf

### P1. Analytics leaf charts (M)

- [x] P1.1 Replaced the four `vue-apexcharts` `<apexchart>` cards in `ComplianceCharts.vue` with the
  lib `CnChartWidget`, fed the series produced by `computeSlaCompliance` / `computeMonthlyTrend` /
  `computeProcessingTimeDistribution` / `computeWeeklyThroughput` (via the unchanged
  `chartShaping.js` builders). Each computed series is registered with OR's analytics-series surface
  (`src/services/analyticsSeriesApi.js` → `POST /api/integrations/analytics/series`) so OR owns
  persistence + render contract + page-widget declaration.
- [x] P1.2 Empty-data degradation preserved: each chart keeps its `v-if length > 0` empty-state
  branch; `registerSeries` is fire-and-forget and never throws into the view (failures degrade to
  the same in-place chart render).

## [procest] Keep domain calc

### P2. Retain SLA logic (S)

- [x] P2.1 `doorlooptijdHelpers.js` SLA functions (`parseDurationToDays`, `getProcessingDays`,
  `getSlaTargetDays`, `buildCaseTypeMap`, `computeSlaCompliance`) are UNCHANGED and still produce the
  `byType` breakdown; `chartShaping.js` (the series builders) is unchanged. Locked by Vitest
  (`doorlooptijdChartShaping.spec.js`, 17 tests).
- [x] P2.2 Removed the direct `vue-apexcharts` import from `ComplianceCharts.vue` (the only chart
  consumer) and dropped `apexcharts` + `vue-apexcharts` from procest's `package.json` direct deps —
  the chart engine is now the lib's, transitively under `@conduction/nextcloud-vue`.

## [procest] Quality gates

### P3. Verify (S)

- [x] P3.1 `openspec validate migrate-sla-dashboard-to-analytics-leaf --strict` exits 0.
- [x] P3.2 `npm run build` green; `eslint` touched files 0 errors; Vitest 230/230 green (SLA calc +
  chart-shaping unchanged); 24/24 hydra gates green.

## RESOLVED (built 2026-06-15)

The earlier "REAL BLOCKER" is now resolved. OpenRegister PR #151 landed a **page-level chart /
series render surface** in the OR integration registry: `AnalyticsSeriesController` +
`AnalyticsSeriesService` register/fetch a pre-computed series (aggregate, page-level — NOT the old
per-object `AnalyticsProvider` report-link list) and declare a matching `chart` page-widget. procest
now consumes it: the SLA maths stays in-app (it computes deadlines / breaches / throughput) and hands
the resulting series to OR; OR owns persistence + the render contract; the lib `CnChartWidget` draws
the chart. The bespoke per-chart ApexCharts plumbing is removed. NOTE: as with the shares migration,
the foundation is a BACKEND render-contract surface; the lib does not yet ship a component that
*fetches* `analytics/series` and auto-renders, so procest registers the series with OR (the leaf
surface) and renders the same contract through the lib's declarative `CnChartWidget` — consumption
per ADR-022, not a re-implemented chart engine.
