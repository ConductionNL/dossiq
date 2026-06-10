# Tasks: archief-edepot-handover-05-sip-submission

> **Build status (hydra audit).** Greenfield. No archief schemas, services, or UI exist on dev. The 8-member archief-edepot-handover chain implements GiHandover/MDTO compliance from scratch (BewaarTermijnRegel, OverdrachtTrigger, SipBundel, OverdrachtTransactie, ArchiefBewijs, OverdrachtAuditLog schemas + daemon + sip-bundle generator + e-depot submission adapter + audit/admin UI). Tasks remain [ ] as genuine forward work for the next builder. See chain plan in design.md.

Chain member 5 of 8 (`kind: code`, depends_on member 04). Traces to giant Tasks 9–11 / REQ-ARCH-004, 005.

## 1. BagIt SIP assembly

- [ ] Implement `buildBagIt(sipBundelId)`: create bagit.txt, bag-info.txt, data/ (metadata.xml + PDF/A + originals), manifest-sha256.txt
- [ ] Compute total bundle checksum; store BagIt path in `SipBundel.bundleContent`; set status `ready-for-submission`
- [ ] Implement `BagItManifestBuilder`: SHA-256 per file per RFC 8493
- [ ] Optional: tar.gz compression for transport
- [ ] Read/write `SipBundel` via OpenRegister ObjectService (no bespoke SQL)

## 2. EDepotSubmitter + channels

- [ ] Implement `submitBundle(sipBundelId, eDepotConnectionId)` router: load SIP + openconnector config; route by connectionType; record `OverdrachtTransactie`
- [ ] Implement HttpsSubmitter: POST with Authorization header; parse archief-id from response
- [ ] Implement SftpSubmitter: SSH-key auth, upload + `.complete`, poll acknowledgement for archief-id
- [ ] Implement S3Submitter: PUT with credentials; optional metadata tags
- [ ] Read all credentials from openconnector config; never log secrets

## 3. Exponential-backoff retry

- [ ] Implement `SubmissionRetryDaemon.processRetryQueue()`: re-execute `retrying` transactions with `nextRetryTime ≤ now`
- [ ] Implement backoff schedule (1m, 5m, 30m, 2h, 8h); escalate after attempt 5
- [ ] Each attempt creates a new `OverdrachtTransactie.attemptNumber`; log each retry to `OverdrachtAuditLog`
- [ ] Create console command `archief:retry-submissions`; schedule the daemon every 5 minutes

## 4. Tests

- [ ] Test: build BagIt for a sample bundle; verify structure, manifest format, checksums (idempotent re-compute)
- [ ] Test: HTTPS submission extracts archief-id; SFTP upload + completion + polling; S3 PUT creates object
- [ ] Test: simulated failure retries on schedule; after 5 failures DIV receives escalation
- [ ] Test: checksum-mismatch response sets the transaction failed and preserves the SIP for retry
