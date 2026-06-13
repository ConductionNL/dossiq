---
kind: code
depends_on:
  - archief-edepot-handover-04-document-export
chain:
  - archief-edepot-handover-01-schema-config
  - archief-edepot-handover-02-retention-trigger
  - archief-edepot-handover-03-metadata-bundling
  - archief-edepot-handover-04-document-export
  - archief-edepot-handover-05-sip-submission
  - archief-edepot-handover-06-proof-rollback
  - archief-edepot-handover-07-batch-inspection
  - archief-edepot-handover-08-admin-ui-docs
---

# Proposal: archief-edepot-handover-05-sip-submission

## Summary

This is **spec 5 of 8** in the `archief-edepot-handover` chain. It packages the validated metadata + exported documents into a BagIt SIP with a SHA-256 manifest, then submits the SIP to the configured e-Depot over HTTPS/SFTP/S3 with authentication and exponential-backoff retry, recording each attempt as an `OverdrachtTransactie`. `kind: code`; `depends_on` member 04 (it packages the documents that member produced).

## Why

Packaging + transport is the act of handover. This member assembles the SIP (member 03's XML + member 04's documents) and gets it to the certified archive reliably. Proof capture and rollback on rejection are member 06; this member owns assembly, transport, and retry.

## What Changes

1. **BagItBundler** — `buildBagIt(sipBundelId)` produces the RFC 8493 directory + `bag-info.txt`, optional tar.gz.
2. **BagItManifestBuilder** — SHA-256 per file + total bundle checksum; status → `ready-for-submission`.
3. **EDepotSubmitter** — router selecting HTTPS/SFTP/S3 by openconnector connection type; extracts archief-id; records `OverdrachtTransactie`.
4. **HttpsSubmitter / SftpSubmitter / S3Submitter** — per-channel transport with auth.
5. **SubmissionRetryDaemon** — exponential backoff (1m, 5m, 30m, 2h, 8h), 5-attempt cap, per-attempt `OverdrachtTransactie`, audit logging.

## Impact

- **Affected**: procest (BagIt bundler, submitters, retry daemon), openconnector (e-Depot connection config + transport adapters).
- **Consumes**: `SipBundel` (members 01–04), `OverdrachtTransactie`, `OverdrachtAuditLog` (member 01).
- **Downstream**: member 06 captures proof on success and rolls back on rejection.

## Traceability

Covers giant tasks **9** (BagIt SIP bundler), **10** (EDepotSubmitter HTTPS/SFTP/S3), and **11** (exponential-backoff retry); requirements REQ-ARCH-004 (A/B) and REQ-ARCH-005 (A/B/C). No new scope.
