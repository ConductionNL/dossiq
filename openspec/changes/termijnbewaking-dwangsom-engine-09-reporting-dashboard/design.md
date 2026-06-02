# Design: termijnbewaking-dwangsom-engine-09-reporting-dashboard

## Scope of this member

Read-only reporting + dashboard aggregation. No mutation of termijn/dwangsom state; this member only queries and aggregates the entities produced by members 02–08.

## Approach

### ReportingService.generateQuarterlyReport(periode, afdeling=null)
Query `TermijnInstance` rows created in the period, group by zaaktype, compute per-type KPIs: totaal-zaken, binnen-termijn %, gemiddelde doorlooptijd (beschikkingDatum − startDatum for completed), aantal-verlengingen, aantal-overschrijdingen, aantal-ingebrekestellingen (geldig), totaal-dwangsom (sum of `definitievBedrag`). Output HTML table + CSV/JSON export with report metadata.

### ReportingService.generateDwangsomAuditReport(jaar)
Query `DwangsomUitbetaling` with `werkelijkeBetaaldatum` in the year; for each, join `DwangsomBerekening`, `Ingebrekestelling`, zaak; emit a row {zaak-ref, zaaktype, ingebrekestelling-datum, beschikking-datum, dwangsom-bedrag, betaal-datum, betalings-referentie, status}. Validate required fields populated (warn on gaps). CSV + JSON exports + summary statistics.

### DashboardService.getTermijnKPI(filters)
Aggregate {totalZaken, withinTermijnPercent, avgDurationDays, overrunCount, dwangsomTotal, lastUpdated}; cache results (expire hourly). Expose via `GET /api/procest/dashboard/termijn-kpi`.

## Security (ADR-005)

Reports are management/accountant-facing: the controllers (this member's endpoints + member-10 reporting routes) require the appropriate role (manager/accountant). The dashboard KPI endpoint returns only aggregate counts, no per-burger PII. No `#[NoAdminRequired]`-without-guard endpoints.

## Tests

Unit: KPI calculations correct against a fixture dataset; export format validity (CSV headers, JSON shape). Integration: dashboard endpoint returns correct aggregates and caches.
