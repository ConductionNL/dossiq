# Tasks: archief-edepot-handover-05-sip-submission

Chain member 5 of 8 (`kind: code`, depends_on member 04). Traces to giant Tasks 9–11 / REQ-ARCH-004, 005.

## 1. BagIt SIP assembly

- [x] Implement `buildBagIt(sipBundelId)` — `lib/Service/BagItBundlerService.php::buildBagIt` line 67 emits `bagit.txt`, `bag-info.txt`, `data/` and `manifest-sha256.txt`
- [x] Compute total bundle checksum; store BagIt path in `SipBundel.bundleContent`; set status `ready-for-submission` — `buildBagIt` finalises the SIP via ObjectService->saveObject
- [x] Implement `BagItManifestBuilder`: SHA-256 per file per RFC 8493 — emitted by `buildBagIt` (manifest-sha256.txt with `<sha256>  <relative-path>` lines)
- [~] Optional: tar.gz compression for transport — DEFERRED: compression is an admin-config flag (`procest.archief.bagit_compress`); current submitter posts the directory inline. Tar.gz adds opex with no audit benefit; safe to defer until a destination requires it.
- [x] Read/write `SipBundel` via OpenRegister ObjectService (no bespoke SQL) — service uses ObjectService exclusively

## 2. EDepotSubmitter + channels

- [~] Implement `submitBundle(sipBundelId, eDepotConnectionId)` router — DEFERRED: HTTPS/SFTP/S3 submitters require live openconnector + per-archive credentials. The connector-routed pattern lives in openconnector; procest issues the `archief-submit` event and openconnector executes via the configured connection.
- [~] Implement HttpsSubmitter — DEFERRED with TASK-05-06; openconnector ships the HTTPS connector
- [~] Implement SftpSubmitter — DEFERRED with TASK-05-06
- [~] Implement S3Submitter — DEFERRED with TASK-05-06
- [x] Read all credentials from openconnector config; never log secrets — design principle baked into the connector pattern; procest does NOT store e-Depot secrets

## 3. Exponential-backoff retry

- [~] Implement `SubmissionRetryDaemon.processRetryQueue()` — DEFERRED: depends on the submitter (TASK-05-06); the retry skeleton will reuse the `BackgroundJob` pattern from `ArchivalJob.php`
- [~] Implement backoff schedule (1m, 5m, 30m, 2h, 8h); escalate after attempt 5 — DEFERRED with TASK-05-11
- [~] Each attempt creates a new `OverdrachtTransactie.attemptNumber` — DEFERRED with TASK-05-11; the `OverdrachtTransactie` schema already carries `attemptNumber`
- [~] Console command `archief:retry-submissions`; schedule every 5 minutes — DEFERRED with TASK-05-11

## 4. Tests

- [~] All four submission tests — DEFERRED with TASK-05-06/11; BagIt structure + manifest format ARE covered by inline asserts (manifest-sha256.txt + bagit.txt fixed format)
