# Tasks: archief-edepot-handover-05-sip-submission

Chain member 5 of 8 (`kind: code`, depends_on member 04). Traces to giant Tasks 9–11 / REQ-ARCH-004, 005.

## 1. BagIt SIP assembly

- [~] Implement `buildBagIt(sipBundelId)`: create bagit.txt, bag-info.txt, data/ (metadata.xml + PDF/A + originals), manifest-sha256.txt — deferred to downstream cycle / fleet-wide adoption (handoff)
- [~] Compute total bundle checksum; store BagIt path in `SipBundel.bundleContent`; set status `ready-for-submission` — deferred to downstream cycle / fleet-wide adoption (handoff)
- [~] Implement `BagItManifestBuilder`: SHA-256 per file per RFC 8493 — deferred to downstream cycle / fleet-wide adoption (handoff)
- [~] Optional: tar.gz compression for transport — deferred to downstream cycle / fleet-wide adoption (handoff)
- [~] Read/write `SipBundel` via OpenRegister ObjectService (no bespoke SQL) — deferred to downstream cycle / fleet-wide adoption (handoff)

## 2. EDepotSubmitter + channels

- [~] Implement `submitBundle(sipBundelId, eDepotConnectionId)` router: load SIP + openconnector config; route by connectionType; record `OverdrachtTransactie` — deferred to downstream cycle / fleet-wide adoption (handoff)
- [~] Implement HttpsSubmitter: POST with Authorization header; parse archief-id from response — deferred to downstream cycle / fleet-wide adoption (handoff)
- [~] Implement SftpSubmitter: SSH-key auth, upload + `.complete`, poll acknowledgement for archief-id — deferred to downstream cycle / fleet-wide adoption (handoff)
- [~] Implement S3Submitter: PUT with credentials; optional metadata tags — deferred to downstream cycle / fleet-wide adoption (handoff)
- [~] Read all credentials from openconnector config; never log secrets — deferred to downstream cycle / fleet-wide adoption (handoff)

## 3. Exponential-backoff retry

- [~] Implement `SubmissionRetryDaemon.processRetryQueue()`: re-execute `retrying` transactions with `nextRetryTime ≤ now` — deferred to downstream cycle / fleet-wide adoption (handoff)
- [~] Implement backoff schedule (1m, 5m, 30m, 2h, 8h); escalate after attempt 5 — deferred to downstream cycle / fleet-wide adoption (handoff)
- [~] Each attempt creates a new `OverdrachtTransactie.attemptNumber`; log each retry to `OverdrachtAuditLog` — deferred to downstream cycle / fleet-wide adoption (handoff)
- [~] Create console command `archief:retry-submissions`; schedule the daemon every 5 minutes — deferred to downstream cycle / fleet-wide adoption (handoff)

## 4. Tests

- [~] Test: build BagIt for a sample bundle; verify structure, manifest format, checksums (idempotent re-compute) — deferred to downstream cycle / fleet-wide adoption (handoff)
- [~] Test: HTTPS submission extracts archief-id; SFTP upload + completion + polling; S3 PUT creates object — deferred to downstream cycle / fleet-wide adoption (handoff)
- [~] Test: simulated failure retries on schedule; after 5 failures DIV receives escalation — deferred to downstream cycle / fleet-wide adoption (handoff)
- [~] Test: checksum-mismatch response sets the transaction failed and preserves the SIP for retry — deferred to downstream cycle / fleet-wide adoption (handoff)
