# Design: archief-edepot-handover-05-sip-submission

## Scope

BagIt SIP assembly, manifest/checksums, e-Depot submission over HTTPS/SFTP/S3, and exponential-backoff retry. Proof capture and rollback-on-rejection are member 06; batch orchestration is member 07.

## Declarative-first (ADR-031)

BagIt packaging, checksum computation, and transport are imperative integration glue (RFC 8493, SFTP/S3 protocols) with no declarative analogue per ADR-031. The e-Depot endpoint definitions live as declarative openconnector connections; this member routes to them rather than hard-coding endpoints.

## Data access (ADR-001)

`SipBundel` reads/writes and `OverdrachtTransactie` / `OverdrachtAuditLog` writes go through the OpenRegister ObjectService. e-Depot connection config is read from openconnector. No bespoke SQL.

## Service layout

### BagItBundler + BagItManifestBuilder
- `buildBagIt(sipBundelId)` — create `bagit.txt`, `bag-info.txt` (zaakId, zaaktype, transfer-date, e-Depot), `data/` (metadata.xml + PDF/A + originals), `manifest-sha256.txt`; compute total bundle checksum; store path in `SipBundel.bundleContent`; set status `ready-for-submission`. Optional tar.gz for transport.

### EDepotSubmitter (router)
- `submitBundle(sipBundelId, eDepotConnectionId)` — load SIP + openconnector config; route by connectionType (HTTP/SFTP/S3); return {status, archivId, receipt}; record `OverdrachtTransactie`.

### HttpsSubmitter / SftpSubmitter / S3Submitter
- HTTPS: POST (multipart or application/zip) with Authorization header (API-key/OAuth2 from openconnector); parse archief-id.
- SFTP: SSH-key auth; upload BagIt tar.gz to drop-folder + `.complete`; poll acknowledged folder for archief-id.
- S3: PUT to S3-compatible storage with credentials; optional metadata tags.

### SubmissionRetryDaemon
- `processRetryQueue()` — re-execute `retrying` transactions whose `nextRetryTime ≤ now`; backoff schedule 1m/5m/30m/2h/8h; after attempt 5 escalate (no auto-retry); each attempt is a new `OverdrachtTransactie.attemptNumber`; log every retry.

## Security (ADR-005)

Credentials (API-key, SSH key, S3 secret) are read from openconnector connection config, never embedded in code or logs — the audit log records channel + status, not secrets. Checksum mismatch reported by the e-Depot is surfaced as a transaction failure for member 06 to handle; the SIP is preserved for diagnostics. No new user-facing endpoint here.

## Traceability

Giant Task 9 (BagIt), Task 10 (submitters), Task 11 (retry); REQ-ARCH-004-A/B, REQ-ARCH-005-A/B/C.
