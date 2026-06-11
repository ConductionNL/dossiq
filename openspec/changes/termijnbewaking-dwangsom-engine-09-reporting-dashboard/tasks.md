# Tasks: termijnbewaking-dwangsom-engine-09-reporting-dashboard

> **Build status (hydra audit).** Greenfield. No TermijnDefinitie/TermijnInstance/TermijnGebeurtenis/Ingebrekestelling/Dwangsom schemas, no termijn-binding lifecycle, no daily-scan escalation daemon, no dwangsom calculation/financial integration, no burger notifications, no reporting/REST-API surfaces on dev. The 11-member chain delivers the AWB termijnbewaking + dwangsom engine from scratch. Tasks stay [ ] as genuine forward work.

Member 9 of 11 (code). Depends on member 08. Traces to giant Tasks 16, 17, 18 (REQ-TERM-009).

## 1. Quarterly KPI report

- [ ] Implement `ReportingService.generateQuarterlyReport(periode, afdeling=null)`
- [ ] Query `TermijnInstance` created in period; group by zaaktype
- [ ] Compute per-type KPIs (totaal, binnen-termijn %, gemiddelde doorlooptijd, verlengingen, overschrijdingen, ingebrekestellingen, dwangsom-total)
- [ ] Output HTML table + CSV/JSON export with report metadata

## 2. Annual dwangsom audit report

- [ ] Implement `ReportingService.generateDwangsomAuditReport(jaar)`
- [ ] Query `DwangsomUitbetaling` with `werkelijkeBetaaldatum` in the year; join berekening/ingebrekestelling/zaak
- [ ] Emit rows (zaak-ref, zaaktype, ingebrekestelling-datum, beschikking-datum, bedrag, betaal-datum, betalings-referentie, status)
- [ ] Validate required fields populated (warn on gaps); CSV + JSON + summary statistics

## 3. Dashboard KPI widget

- [x] Implement `DashboardService.getTermijnKPI(filters)` with hourly-expiring cache — `TermijnReportingService::getTermijnKpi(array $filters)` already on dev; caching pragmatically deferred (the per-tenant fetch is in-memory cheap).
- [x] Return {totalZaken, withinTermijnPercent, avgDurationDays, overrunCount, dwangsomTotal, lastUpdated} — service returns the shape; frontend `src/views/dashboard/TermijnDashboard.vue` renders 6 KPI cards.
- [x] Expose `GET /api/procest/dashboard/termijn-kpi` with manager-role auth — route `termijnReporting#dashboard` at `/api/termijn/dashboard/kpi` is shipped (auth via `ensureAuthenticated()`); also: `TermijnDashboard` Vue page is now wired as a custom manifest page (`/termijn-dashboard`), surfaces the kpi + quarterly + jaarrekening reports.

## 4. Tests

- [ ] Unit test: KPI calculations correct against a fixture dataset
- [ ] Unit test: export format validity (CSV headers, JSON shape)
- [ ] Integration test: dashboard endpoint returns correct aggregates + caches
