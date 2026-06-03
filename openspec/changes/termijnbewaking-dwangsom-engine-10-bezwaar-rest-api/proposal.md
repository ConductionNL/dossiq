---
kind: code
depends_on: [termijnbewaking-dwangsom-engine-09-reporting-dashboard]
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

# Proposal: termijnbewaking-dwangsom-engine-10-bezwaar-rest-api

Member 10 of 11 in the **termijnbewaking-dwangsom-engine** chain (ADR-032). Predecessor: `termijnbewaking-dwangsom-engine-09-reporting-dashboard`. This `kind: code` member implements bezwaar handling against a dwangsom-beschikking (AWB 4:18) and the comprehensive REST API surface that exposes every service built across the chain.

## Why

A burger may dispute the dwangsom amount; AWB 4:18 requires the calculation and payment to be frozen pending resolution, and the amount adjusted if the bezwaar is upheld. Separately, the engine is consumed by every procest capability and by dashboard/portal — it needs a coherent, authorization-checked REST surface. Both are the final consumer-facing surface that ties the chain together.

## What Changes (this member)

1. `DwangsomBezwaarService` — register bezwaar (freeze accrual, pause uitbetaling), resolve bezwaar (adjust amount, resume payment).
2. Comprehensive REST endpoints across `TermijnController`, `IngebrekestellingController`, `DwangsomController`, `ReportingController` with input validation, permission checks (ADR-023), and error handling.

## Impact

- **Affected**: procest (`DwangsomBezwaarService`, the four controllers + `appinfo/routes.php`).
- **Traces to giant tasks**: Task 19 (bezwaar handling), Task 20 (REST API), REQ-TERM-010.
- **Depends on**: members 02–09 (the services the endpoints expose; the bezwaar freeze acts on the member-06/07 dwangsom + uitbetaling).
