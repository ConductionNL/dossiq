---
kind: code
depends_on: [termijnbewaking-dwangsom-engine-08-burger-notifications]
chain:
  - termijnbewaking-dwangsom-engine-01-schemas-and-seed
  - termijnbewaking-dwangsom-engine-02-termijn-binding-lifecycle
  - termijnbewaking-dwangsom-engine-03-pause-extension
  - termijnbewaking-dwangsom-engine-04-daily-scan-escalation
  - termijnbewaking-dwangsom-engine-05-ingebrekestelling
  - termijnbewaking-dwangsom-engine-06-dwangsom-calculation
  - termijnbewaking-dwangsom-engine-07-financial-integration
  - termijnbewaking-dwangsom-engine-08-burger-notifications
  - termijnbewaking-dwangsom-engine-09-reporting-dashboard
  - termijnbewaking-dwangsom-engine-10-bezwaar-rest-api
  - termijnbewaking-dwangsom-engine-11-tests-admin-docs
---

# Proposal: termijnbewaking-dwangsom-engine-09-reporting-dashboard

Member 9 of 11 in the **termijnbewaking-dwangsom-engine** chain (ADR-032). Predecessor: `termijnbewaking-dwangsom-engine-08-burger-notifications`. This `kind: code` member implements the management reporting (quarterly KPI, annual dwangsom audit) and the dashboard KPI widget endpoint.

## Why

Management needs evidence-based KPI tracking (% within deadline, average duration, overruns by zaaktype/afdeling) and the accountant needs an audit-compliant annual dwangsom listing for the jaarrekening. ISO 9001 treats termijnbewaking as a formal quality process; without reporting, the engine's compliance value is invisible to leadership and unverifiable in audit.

## What Changes (this member)

1. `ReportingService.generateQuarterlyReport()` — per-zaaktype KPI breakdown with HTML + CSV/JSON export.
2. `ReportingService.generateDwangsomAuditReport()` — annual CSV/JSON of all dwangsommen for the jaarrekening.
3. `DashboardService.getTermijnKPI()` + `GET /api/procest/dashboard/termijn-kpi` — real-time aggregated KPI widget (cached hourly).

## Impact

- **Affected**: procest (`ReportingService`, `DashboardService`, `ReportingController`/`DashboardController`), procest-dashboard (widget consumer).
- **Traces to giant tasks**: Task 16 (quarterly KPI), Task 17 (annual audit), Task 18 (dashboard widget), REQ-TERM-009.
- **Depends on**: members 02–08 (the data the reports aggregate).
