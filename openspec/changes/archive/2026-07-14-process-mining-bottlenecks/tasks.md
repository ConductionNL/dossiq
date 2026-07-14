# Tasks: process-mining-bottlenecks

## 1. Aggregation service
- [x] 1.1 `lib/Service/ProcessMiningService.php` — `getReport(array $params): array`
      orchestration (period from/to, optional caseType filter).
- [x] 1.2 `computeDwellIntervals()` — per-(case, status-visit) dwell hours;
      handles open cases (uses "now"), closed cases (uses `endDate`),
      zero-duration (identical timestamps), missing history (no records),
      and period-window clamping (entry timestamp must fall in `[from, to]`).
- [x] 1.3 `aggregateDwellStats()` — median/p90/mean hours + visit count per
      status (nearest-rank percentile).
- [x] 1.4 `rankBottlenecks()` — median dwell x visit-volume score, sorted
      descending.
- [x] 1.5 `computeCaseTransitions()` / `computeTransitionMatrix()` —
      from→to frequency matrix; a transition is rework when its target
      status was already visited earlier in that case's own history.
- [x] 1.6 `computeThroughputTrend()` — cases closed per ISO week within
      `[from, to]`, zero-seeded so gaps render as `0` not "missing".
- [x] 1.7 Unit tests covering all of the above (18 cases): dwell math edge
      cases, aggregation, ranking, rework detection (linear vs revisit),
      transition-matrix rework-percent, throughput bucketing, full
      `getReport()` orchestration, caseType filtering.

## 2. Controller + routes
- [x] 2.1 `lib/Controller/ProcessMiningController.php` — `report()`, reading
      `from`/`to`/`caseType` query params.
- [x] 2.2 Gated via `IGroupManager` allow-list
      (`controllers`/`beheerders`/`admin`) + `isAdmin()` fallback, mirroring
      `Iv3ReportController::ALLOWED_GROUPS`/`isAllowed()`/`ensureAllowed()`.
- [x] 2.3 `Y-m-d` validation on `from`/`to`; service exceptions translated to
      a static 500 message (never `$e->getMessage()`).
- [x] 2.4 Route: `processMining#report`
      `GET /api/reports/process-mining`.
- [x] 2.5 Unit tests: 401 unauthenticated, 403 outside allowed groups, 200
      for a `controllers`-group member (not just admin), 400 invalid `from`,
      happy-path delegation with param mapping, 500 on service exception
      with no leaked exception detail.

## 3. Frontend
- [x] 3.1 `src/views/dashboard/ProcessMiningDashboard.vue` — period preset +
      case-type picker (structural copy of `DoorlooptijdDashboard.vue`'s
      preset row + `TermijnDashboard.vue`'s `NcSelect` filter), `CnKpiGrid`
      + `CnStatsBlock` KPI tiles, `CnChartWidget` dwell-time bar chart +
      throughput line chart, plain bottleneck-ranking table, rework-rate
      `NcNoteCard` callout.
- [x] 3.2 `src/views/processMining/processMiningShaping.js` — pure
      series/categories/table shaping (mirrors
      `doorlooptijd/components/chartShaping.js`).
- [x] 3.3 `src/services/processMiningApi.js` — single `fetchProcessMiningReport()`
      wrapper (mirrors `doorlooptijdApi.js`).
- [x] 3.4 Register in `src/customComponents.js`, `src/manifest.json`
      (`type: "custom"` page, same shape as `Iv3ReportDashboard`),
      `src/menu-layout.json` (nav entry inside `AnalyticsGroup`).
- [x] 3.5 i18n: every new `t('procest', '...')` string has an EN source
      string and an NL translation pair (`node tests/l10n/check-l10n.js`
      green).
- [x] 3.6 Vitest for `processMiningShaping.js` (11 cases): series/category
      alignment, empty-data branches, bottleneck flatten+sort+limit,
      transition-weighted overall rework % aggregation.

## 4. Verification
- [x] 4.1 `openspec validate process-mining-bottlenecks --type change --strict`
      passes.
- [x] 4.2 Full PHPUnit suite green (CI-equivalent `php:8.3-cli` container,
      `phpunit-unit.xml`) — not just the new tests (1302 tests).
- [x] 4.3 Full vitest suite green (258 tests).
- [x] 4.4 `npm run build` exits 0.
- [x] 4.5 `node tests/l10n/check-l10n.js` exits 0 (EN source + EN/NL parity).
- [x] 4.6 Archive the change under
      `openspec/changes/archive/2026-07-14-process-mining-bottlenecks/`.

## Follow-ups (out of scope for this change)
- Drill-down from a bottleneck-table row into the actual case list stuck at
  that status (would reuse `CnDataTable`/the standard object-table widget,
  filtered by `case.status`).
- Per-handler/assignee dwell-time breakdown (would need `case.assignee` in
  the aggregation — deferred pending a concrete reporting requirement).
- Publishing the dwell/throughput series to OpenRegister's page-level
  analytics-series surface (the pattern `ComplianceCharts.vue` uses for the
  doorlooptijd dashboard), so the figures also surface on generic OR
  analytics pages.
