---
kind: code
depends_on: [termijnbewaking-dwangsom-engine-03-pause-extension]
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

# Proposal: termijnbewaking-dwangsom-engine-04-daily-scan-escalation

Member 4 of 11 in the **termijnbewaking-dwangsom-engine** chain (ADR-032). Predecessor: `termijnbewaking-dwangsom-engine-03-pause-extension`. This `kind: code` member adds the daily scan cronjob and the escalation matrix: pro-active alerts at 14d/7d/2d thresholds, pause-expiry alerts, and overschrijding detection that flips `status` to `overschreden`.

## Why

A deadline engine without pro-active alerts is a passive ledger. The Nationale ombudsman's structural finding is that handlers miss deadlines because nothing warns them in time. Escalating notifications (handler → teamlead → manager) at fixed thresholds, plus automatic overschrijding marking, give human attention the runway it needs and create the audit record that a missed deadline was flagged.

## What Changes (this member)

1. `DailyTermijnScanJob` — queries active instances, buckets by days-to-deadline (14/7/2/0), drives escalation, marks overschrijding.
2. `EscalationService` + `escalation-matrix.json` — threshold × recipient × priority, with duplicate-suppression per threshold.
3. Pause-expiry detection (the pause-deadline stored in member 03) raises an AWB 4:5 advice alert.

## Impact

- **Affected**: procest (`DailyTermijnScanJob`, `EscalationService`, escalation matrix config).
- **Traces to giant tasks**: Task 5 (daily scan cronjob), Task 6 (escalation matrix), REQ-TERM-004, REQ-TERM-002-B.
- **Depends on**: member 03 (pause-deadline) and member 02 (instances). The dwangsom-accrual call from the scan is wired in member 06.
