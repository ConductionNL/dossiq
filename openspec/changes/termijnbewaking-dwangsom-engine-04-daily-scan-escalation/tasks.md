# Tasks: termijnbewaking-dwangsom-engine-04-daily-scan-escalation

Member 4 of 11 (code). Depends on member 03. Traces to giant Tasks 5, 6 (REQ-TERM-004, REQ-TERM-002-B).

## 1. DailyTermijnScanJob

- [~] Create `DailyTermijnScanJob` BackgroundJob with scheduled execution (default 01:00 UTC) — deferred to downstream cycle / fleet-wide adoption (handoff)
- [~] Query active `TermijnInstance` rows (status not in voltooid/overschreden/ingetrokken) — deferred to downstream cycle / fleet-wide adoption (handoff)
- [~] Compute days-to-deadline; bucket into 14d / 7d / 2d / 0 (overschreden) — deferred to downstream cycle / fleet-wide adoption (handoff)
- [~] For overschrijding: set status=overschreden, record `overschreden` event, emit `termijn-overschreden` — deferred to downstream cycle / fleet-wide adoption (handoff)
- [~] Detect pause-expiry; record `pauze-verlopen`, raise AWB 4:5 advice alert, block auto-continuation — deferred to downstream cycle / fleet-wide adoption (handoff)
- [~] Job-level error handling so one bad row does not abort the sweep — deferred to downstream cycle / fleet-wide adoption (handoff)

## 2. EscalationService + matrix

- [~] Define `escalation-matrix.json`: threshold × {handler, teamleader, manager} × priority — deferred to downstream cycle / fleet-wide adoption (handoff)
- [~] Implement `EscalationService.notifyThreshold(termijnInstance, thresholdDays)` — deferred to downstream cycle / fleet-wide adoption (handoff)
- [~] Resolve role assignments (handler/teamleader/manager) from the case — deferred to downstream cycle / fleet-wide adoption (handoff)
- [~] Render per-threshold message template (case-ID, deadline, action-needed) — deferred to downstream cycle / fleet-wide adoption (handoff)
- [~] Send via procest notification-router (Nextcloud notificatie + email) — deferred to downstream cycle / fleet-wide adoption (handoff)
- [~] Track sent thresholds in `notificatiesVerstuurd` to prevent duplicates — deferred to downstream cycle / fleet-wide adoption (handoff)

## 3. Tests

- [~] Unit test: bucketing + escalation-recipient distribution per threshold — deferred to downstream cycle / fleet-wide adoption (handoff)
- [~] Unit test: duplicate suppression per threshold — deferred to downstream cycle / fleet-wide adoption (handoff)
- [~] Integration test: simulated time advance flips a passed instance to overschreden + records event — deferred to downstream cycle / fleet-wide adoption (handoff)
