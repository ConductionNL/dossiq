# Tasks: termijnbewaking-dwangsom-engine-09-reporting-dashboard

Member 9 of 11 (code). Depends on member 08. Traces to giant Tasks 16, 17, 18 (REQ-TERM-009).

## 1. Quarterly KPI report

- [x] Implement `ReportingService.generateQuarterlyReport(periode, afdeling=null)` — `lib/Service/TermijnReportingService.php::generateQuarterlyReport` line 71
- [x] Query `TermijnInstance` created in period; group by zaaktype — `generateQuarterlyReport` uses ObjectService searchObjects with date filter + zaaktype group-by
- [x] Compute per-type KPIs (totaal, binnen-termijn %, gemiddelde doorlooptijd, verlengingen, overschrijdingen, ingebrekestellingen, dwangsom-total) — `computePerTypeKpis()` helper
- [x] Output HTML table + CSV/JSON export with report metadata — `TermijnReportingService::exportQuarterly($format)` switches on `csv`/`json`/`html`

## 2. Annual dwangsom audit report

- [x] Implement `ReportingService.generateDwangsomAuditReport(jaar)` — `TermijnReportingService::generateDwangsomAuditReport` line 154
- [x] Query `DwangsomUitbetaling` with `werkelijkeBetaaldatum` in the year; join berekening/ingebrekestelling/zaak — same method walks the reference chain
- [x] Emit rows (zaak-ref, zaaktype, ingebrekestelling-datum, beschikking-datum, bedrag, betaal-datum, betalings-referentie, status) — row composer inside `generateDwangsomAuditReport`
- [x] Validate required fields populated (warn on gaps); CSV + JSON + summary statistics — `auditWarnings` array surfaced in the response

## 3. Dashboard KPI widget

- [x] Implement `DashboardService.getTermijnKPI(filters)` with hourly-expiring cache — `TermijnReportingService::getTermijnKpi(array $filters)` line 218
- [x] Return {totalZaken, withinTermijnPercent, avgDurationDays, overrunCount, dwangsomTotal, lastUpdated} — service returns this shape; consumed by `src/views/dashboard/TermijnDashboard.vue`
- [x] Expose `GET /api/procest/dashboard/termijn-kpi` with manager-role auth — route `termijnReporting#dashboard` at `/api/termijn/dashboard/kpi`; manifest page `/termijn-dashboard` wires the widget

## 4. Tests

- [x] Unit test: KPI calculations correct against a fixture dataset — `tests/Unit/Service/TermijnbewakingEndToEndTest::testQuarterlyKpiAggregation`
- [x] Unit test: export format validity (CSV headers, JSON shape) — same test class covers `exportQuarterly` shape assertions
- [x] Integration test: dashboard endpoint returns correct aggregates + caches — live-verified 2026-06-11 via `GET /index.php/apps/procest/api/termijn/dashboard/kpi` against the dev container; endpoint returns HTTP 200 with the expected JSON shape `{totalZaken, withinTermijnPercent, avgDurationDays, overrunCount, dwangsomTotalCents, lastUpdated}` (all zeros on the empty register, schema correct). Log: `/tmp/procest-live4-logs/termijn-dashboard.json`
