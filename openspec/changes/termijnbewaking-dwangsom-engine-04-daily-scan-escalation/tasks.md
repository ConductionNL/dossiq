# Tasks: termijnbewaking-dwangsom-engine-04-daily-scan-escalation

> **Build status (hydra audit).** Greenfield. No TermijnDefinitie/TermijnInstance/TermijnGebeurtenis/Ingebrekestelling/Dwangsom schemas, no termijn-binding lifecycle, no daily-scan escalation daemon, no dwangsom calculation/financial integration, no burger notifications, no reporting/REST-API surfaces on dev. The 11-member chain delivers the AWB termijnbewaking + dwangsom engine from scratch. Tasks stay [ ] as genuine forward work.

Member 4 of 11 (code). Depends on member 03. Traces to giant Tasks 5, 6 (REQ-TERM-004, REQ-TERM-002-B).

## 1. DailyTermijnScanJob

- [ ] Create `DailyTermijnScanJob` BackgroundJob with scheduled execution (default 01:00 UTC)
- [ ] Query active `TermijnInstance` rows (status not in voltooid/overschreden/ingetrokken)
- [ ] Compute days-to-deadline; bucket into 14d / 7d / 2d / 0 (overschreden)
- [ ] For overschrijding: set status=overschreden, record `overschreden` event, emit `termijn-overschreden`
- [ ] Detect pause-expiry; record `pauze-verlopen`, raise AWB 4:5 advice alert, block auto-continuation
- [ ] Job-level error handling so one bad row does not abort the sweep

## 2. EscalationService + matrix

- [ ] Define `escalation-matrix.json`: threshold × {handler, teamleader, manager} × priority
- [ ] Implement `EscalationService.notifyThreshold(termijnInstance, thresholdDays)`
- [ ] Resolve role assignments (handler/teamleader/manager) from the case
- [ ] Render per-threshold message template (case-ID, deadline, action-needed)
- [ ] Send via procest notification-router (Nextcloud notificatie + email)
- [ ] Track sent thresholds in `notificatiesVerstuurd` to prevent duplicates

## 3. Tests

- [ ] Unit test: bucketing + escalation-recipient distribution per threshold
- [ ] Unit test: duplicate suppression per threshold
- [ ] Integration test: simulated time advance flips a passed instance to overschreden + records event
