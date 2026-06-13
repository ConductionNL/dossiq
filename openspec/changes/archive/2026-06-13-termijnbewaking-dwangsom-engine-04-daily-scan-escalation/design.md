# Design: termijnbewaking-dwangsom-engine-04-daily-scan-escalation

## Scope of this member

The daily cronjob and escalation matrix. Consumes member-02 instances and the member-03 pause-deadline. The dwangsom-accrual loop the scan eventually drives is added by member 06 (which extends `DailyTermijnScanJob`); this member ships the termijn-threshold + overschrijding + pause-expiry passes only.

## Approach

### DailyTermijnScanJob (Nextcloud BackgroundJob)
1. Query active `TermijnInstance` rows (`status` not in {voltooid, overschreden, ingetrokken}).
2. Compute days-to-deadline; bucket into 14d / 7d / 2d / 0 (overschreden).
3. For each bucket call `EscalationService.notifyThreshold()`.
4. For passed deadlines: set `status = overschreden`, record `overschreden` event, emit `termijn-overschreden`.
5. Detect pause-expiry (pause-deadline passed without aanvulling) → record `pauze-verlopen`, raise AWB 4:5 advice alert, flag for manual review.
- Runs daily at a configured time (default 01:00 UTC); job-level error handling so one bad row does not abort the sweep.

### EscalationService + escalation-matrix.json
- Matrix: threshold {14, 7, 2, 0} × targets {handler, teamleader, manager} × priority.
- `notifyThreshold(termijnInstance, thresholdDays)` resolves role assignments from the case, renders a per-threshold message, sends via the procest notification-router.
- Duplicate-suppression: `TermijnInstance.notificatiesVerstuurd` records which thresholds were already sent so a re-run does not re-notify.

## Security (ADR-005)

The scan runs as a system BackgroundJob (no per-request auth). Notifications target only users with case access (resolved server-side). No user-supplied input drives recipient resolution.

## Tests

Unit: bucketing correctness, escalation-recipient distribution per threshold, duplicate suppression. Integration: simulated time advance flips a passed instance to `overschreden` and records the event.
