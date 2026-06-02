# Tasks: termijnbewaking-dwangsom-engine-09-reporting-dashboard

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

- [ ] Implement `DashboardService.getTermijnKPI(filters)` with hourly-expiring cache
- [ ] Return {totalZaken, withinTermijnPercent, avgDurationDays, overrunCount, dwangsomTotal, lastUpdated}
- [ ] Expose `GET /api/procest/dashboard/termijn-kpi` with manager-role auth

## 4. Tests

- [ ] Unit test: KPI calculations correct against a fixture dataset
- [ ] Unit test: export format validity (CSV headers, JSON shape)
- [ ] Integration test: dashboard endpoint returns correct aggregates + caches
