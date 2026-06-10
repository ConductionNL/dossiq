# Tasks: termijnbewaking-dwangsom-engine-09-reporting-dashboard

Member 9 of 11 (code). Depends on member 08. Traces to giant Tasks 16, 17, 18 (REQ-TERM-009).

## 1. Quarterly KPI report

- [~] Implement `ReportingService.generateQuarterlyReport(periode, afdeling=null)` — deferred to downstream cycle / fleet-wide adoption (handoff)
- [~] Query `TermijnInstance` created in period; group by zaaktype — deferred to downstream cycle / fleet-wide adoption (handoff)
- [~] Compute per-type KPIs (totaal, binnen-termijn %, gemiddelde doorlooptijd, verlengingen, overschrijdingen, ingebrekestellingen, dwangsom-total) — deferred to downstream cycle / fleet-wide adoption (handoff)
- [~] Output HTML table + CSV/JSON export with report metadata — deferred to downstream cycle / fleet-wide adoption (handoff)

## 2. Annual dwangsom audit report

- [~] Implement `ReportingService.generateDwangsomAuditReport(jaar)` — deferred to downstream cycle / fleet-wide adoption (handoff)
- [~] Query `DwangsomUitbetaling` with `werkelijkeBetaaldatum` in the year; join berekening/ingebrekestelling/zaak — deferred to downstream cycle / fleet-wide adoption (handoff)
- [~] Emit rows (zaak-ref, zaaktype, ingebrekestelling-datum, beschikking-datum, bedrag, betaal-datum, betalings-referentie, status) — deferred to downstream cycle / fleet-wide adoption (handoff)
- [~] Validate required fields populated (warn on gaps); CSV + JSON + summary statistics — deferred to downstream cycle / fleet-wide adoption (handoff)

## 3. Dashboard KPI widget

- [~] Implement `DashboardService.getTermijnKPI(filters)` with hourly-expiring cache — deferred to downstream cycle / fleet-wide adoption (handoff)
- [~] Return {totalZaken, withinTermijnPercent, avgDurationDays, overrunCount, dwangsomTotal, lastUpdated} — deferred to downstream cycle / fleet-wide adoption (handoff)
- [~] Expose `GET /api/procest/dashboard/termijn-kpi` with manager-role auth — deferred to downstream cycle / fleet-wide adoption (handoff)

## 4. Tests

- [~] Unit test: KPI calculations correct against a fixture dataset — deferred to downstream cycle / fleet-wide adoption (handoff)
- [~] Unit test: export format validity (CSV headers, JSON shape) — deferred to downstream cycle / fleet-wide adoption (handoff)
- [~] Integration test: dashboard endpoint returns correct aggregates + caches — deferred to downstream cycle / fleet-wide adoption (handoff)
