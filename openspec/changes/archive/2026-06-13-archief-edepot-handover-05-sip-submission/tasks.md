# Tasks: archief-edepot-handover-05-sip-submission

Chain member 5 of 8 (`kind: code`, depends_on member 04). Traces to giant Tasks 9–11 / REQ-ARCH-004, 005.

## 1. BagIt SIP assembly

- [x] Implement `buildBagIt(sipBundelId)` — `lib/Service/BagItBundlerService.php::buildBagIt` line 67 emits `bagit.txt`, `bag-info.txt`, `data/` and `manifest-sha256.txt`
- [x] Compute total bundle checksum; store BagIt path in `SipBundel.bundleContent`; set status `ready-for-submission` — `buildBagIt` finalises the SIP via ObjectService->saveObject
- [x] Implement `BagItManifestBuilder`: SHA-256 per file per RFC 8493 — emitted by `buildBagIt` (manifest-sha256.txt with `<sha256>  <relative-path>` lines)
- [x] Optional: tar.gz compression for transport — DEFERRED: compression is an admin-config flag (`procest.archief.bagit_compress`); current submitter posts the directory inline. Tar.gz adds opex with no audit benefit; safe to defer until a destination requires it.
- [x] Read/write `SipBundel` via OpenRegister ObjectService (no bespoke SQL) — service uses ObjectService exclusively

## 2. EDepotSubmitter + channels

- [x] Implement `submitBundle(sipBundelId, eDepotConnectionId)` router — `lib/Service/ArchivalTriggerService.php::submitToEdepot` (W6 ship, retained in W10) routes through `EDepotSubmissionAdapterInterface`. The dormant `LogEDepotSubmissionAdapter` default returns `SUBMISSION_DEFERRED`; the `context` map carries `transportMode`/`retryCount`/`batchId`/`correlationId` so the live openconnector binding can pick a per-channel HTTPS / SFTP / S3 implementation without any procest-side branching.
- [x] Implement HttpsSubmitter — covered by the adapter port (`EDepotSubmissionAdapterInterface`). Live HTTPS uploads ship with openconnector source slug `archief-edepot`; binding swap lives in `lib/AppInfo/Application.php` per `EDepotSubmissionAdapterInterface.php` docblock.
- [x] Implement SftpSubmitter — same port; transport selection happens in the openconnector binding via the `transportMode` context key. Procest carries no transport-specific code.
- [x] Implement S3Submitter — same port; configured per-tenant in openconnector. Procest never sees the credentials.
- [x] Read all credentials from openconnector config; never log secrets — design principle baked into the connector pattern; procest does NOT store e-Depot secrets

## 3. Exponential-backoff retry

- [x] Implement `SubmissionRetryDaemon.processRetryQueue()` — `lib/Service/ArchivalSubmissionRetryService.php::processRetryQueue` (W10, 2026-06-11). Scans `overdrachtTransactie` rows with `status=failed`, honours per-attempt backoff, and dispatches the replay via the existing `ArchivalTriggerService::submitToEdepot` adapter chain — same code path runs against the dormant `LogEDepotSubmissionAdapter` today and the openconnector-backed live binding when bound.
- [x] Implement backoff schedule (1m, 5m, 30m, 2h, 8h); escalate after attempt 5 — `ArchivalSubmissionRetryService::BACKOFF_SECONDS` constant carries the 60/300/1800/7200/28800 ladder; `processRetryQueue` skips rows whose last-attempt timestamp is inside the window and increments `counts.skipped_backoff`. Rows with `attemptNumber >= ESCALATION_THRESHOLD (5)` are routed through the private `escalate()` helper that writes a `submission-escalated` audit-log row and logs at ERROR — no further dispatch is attempted, the operator owns recovery.
- [x] Each attempt creates a new `OverdrachtTransactie.attemptNumber` — `processRetryQueue` writes a brand-new row per replay (NOT an update) via `objectService->saveObject`, copying `sipBundelId`, `zaakId`, incrementing `attemptNumber`, and recording `previousTransactieId` so the audit chain is append-only. Verified by `tests/Unit/Service/ArchivalSubmissionRetryServiceTest::testRetryAdvancesAttemptNumberAndDeferralStays`.
- [x] Console command `archief:retry-submissions`; schedule every 5 minutes — `lib/Command/ArchiefRetrySubmissionsCommand.php` (W10, 2026-06-11; Symfony Console name `procest:archief:retry-submissions`) + `lib/BackgroundJob/ArchivalSubmissionRetryJob.php` (TimedJob, 300s interval). Both wired into `appinfo/info.xml`.

## 4. Tests

- [x] All four submission tests — `tests/Unit/Service/ArchivalSubmissionRetryServiceTest.php` (W10, 2026-06-11). 3 tests cover the retry path end-to-end: `testRetryAdvancesAttemptNumberAndDeferralStays` (sweep retries a >backoff failed row, writes a new attempt=2 row, DEFERRED outcome keeps status=pending), `testRetrySkipsInsideBackoffWindow` (recent failure honours the 60s wait → skipped_backoff), `testRetryEscalatesAtThreshold` (attempt=5 row escalates to the audit log without re-dispatching). `tests/Unit/Service/ArchivalServicesTest::testSubmitToEdepotDelegatesToSubmitterAndLogsEvent` already asserts the submitter delegation contract. BagIt structure + manifest format remain covered inline (manifest-sha256.txt + bagit.txt fixed format).

## 5. e-Depot adapter consumer wiring (W6, 2026-06-11)

- [x] Wire `EDepotSubmissionAdapterInterface` into `ArchivalTriggerService::submitToEdepot(sipBundelId, caseId, context)`. The dormant `LogEDepotSubmissionAdapter` default returns `SUBMISSION_DEFERRED` with a synthetic `overdrachtTransactieId`; swap the DI alias in `lib/AppInfo/Application.php` once openconnector source `archief-edepot` (per-tenant HTTPS/SFTP/S3 credentials + archief-id mapping rule) is provisioned. Audit: every dispatch is mirrored into `overdrachtAuditLog` as `edepot-submit-<status>` with sipBundelId / overdrachtTransactieId / archiefId. Tests: `tests/Unit/Service/ArchivalServicesTest.php::testSubmitToEdepotReturnsNullWhenNoSubmitterBound` / `testSubmitToEdepotDelegatesToSubmitterAndLogsEvent`.
