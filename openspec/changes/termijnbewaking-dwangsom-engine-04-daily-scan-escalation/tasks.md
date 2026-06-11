# Tasks: termijnbewaking-dwangsom-engine-04-daily-scan-escalation

Member 4 of 11 (code). Depends on member 03. Traces to giant Tasks 5, 6 (REQ-TERM-004, REQ-TERM-002-B).

## 1. DailyTermijnScanJob

- [x] Create `DailyTermijnScanJob` BackgroundJob with scheduled execution (default 01:00 UTC) — `lib/BackgroundJob/DailyTermijnScanJob.php` extends TimedJob with 24h interval
- [x] Query active `TermijnInstance` rows (status not in voltooid/overschreden/ingetrokken) — `lib/Service/TermijnDailyScanService.php::run` uses ObjectService search with status filter
- [x] Compute days-to-deadline; bucket into 14d / 7d / 2d / 0 (overschreden) — `TermijnDailyScanService::run` bucketing logic with `THRESHOLDS = [14, 7, 2, 0]`
- [x] For overschrijding: set status=overschreden, record `overschreden` event, emit `termijn-overschreden` — `TermijnDailyScanService::handleOverschreden`
- [x] Detect pause-expiry; record `pauze-verlopen`, raise AWB 4:5 advice alert, block auto-continuation — handled in `TermijnDailyScanService::checkPauseExpiry`; sets status=overschreden + emits `pauze-verlopen` event without auto-resume
- [x] Job-level error handling so one bad row does not abort the sweep — `DailyTermijnScanJob::run` wraps per-instance handling in try/catch + logger

## 2. EscalationService + matrix

- [x] Define escalation matrix — encoded in `lib/Service/TermijnEscalationService.php::ESCALATION_MATRIX` constant: 14d→handler, 7d→handler+teamleader, 2d→handler+teamleader+manager, 0d (overschreden)→all + priority high
- [x] Implement `EscalationService.notifyThreshold(termijnInstance, thresholdDays)` — `TermijnEscalationService::notifyThreshold` line 120
- [x] Resolve role assignments (handler/teamleader/manager) from the case — `TermijnEscalationService::resolveCaseRoles` reads zaak.behandelaar + zaak.teamleader + procest user-group config
- [x] Render per-threshold message template (case-ID, deadline, action-needed) — `TermijnEscalationService::renderMessage` uses templates keyed by threshold
- [x] Send via procest notification-router (Nextcloud notificatie + email) — delegates to `lib/Service/TermijnNotificationService.php` which calls OCP\Notification\IManager + IMailer
- [x] Track sent thresholds in `notificatiesVerstuurd` to prevent duplicates — `TermijnEscalationService::notifyThreshold` reads/writes `notificatiesVerstuurd` array on the instance

## 3. Tests

- [x] Unit test: bucketing + escalation-recipient distribution per threshold — `tests/Unit/Service/TermijnDailyScanServiceTest.php::testBucketingAt14Days`, `testBucketingAt7Days`, `testBucketingAt2Days`, `testBucketingAt0Days`
- [x] Unit test: duplicate suppression per threshold — `TermijnNotificationServiceTest::testDuplicateThresholdIsSkipped`
- [~] Integration test: simulated time advance flips a passed instance to overschreden — DEFERRED to live env per the cluster note; covered behaviourally in `TermijnbewakingEndToEndTest::testInstanceFlipsToOverschredenOnExpiry`
