---
kind: code
depends_on:
  - archief-edepot-handover-05-sip-submission
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

# Proposal: archief-edepot-handover-06-proof-rollback

## Summary

This is **spec 6 of 8** in the `archief-edepot-handover` chain. It handles the two terminal outcomes of a submission (member 05): on success it captures proof of transfer (`ArchiefBewijs`, read-only case attachment, checksum verification); on rejection it rolls back, preserving the dossier in procest, marking the trigger failed, and notifying DIV with corrective action plus a retry-after-correction endpoint. `kind: code`; `depends_on` member 05.

## Why

A submission is not complete until either an auditable proof exists or a clean, recoverable failure state is recorded. This member closes the single-case loop. Bulk orchestration and inspection exports build on it in member 07.

## What Changes

1. **ProofOfTransferRecorder** — `createArchiefBewijs()`, `attachProofToCase()` (read-only), `verifyIntegrity()`.
2. **RollbackManager** — `onIngestionFailure()` marks the transaction failed-final and trigger `gefaald`, preserves the SIP, and crafts a DIV notification; `recommendCorrectiveAction()` maps error codes to instructions.
3. **Retry-after-correction** — `POST /api/archief/triggers/{triggerId}/retry` re-bundles + re-submits with current case state, logging both old and new transactions.

## Impact

- **Affected**: procest (proof recorder, rollback manager, retry controller).
- **Consumes**: `OverdrachtTransactie`, `SipBundel`, `ArchiefBewijs`, `OverdrachtTrigger`, `OverdrachtAuditLog` (members 01–05).
- **Downstream**: member 07 batch processing reuses the proof/rollback per-case path; inspection export reads `ArchiefBewijs`.

## Traceability

Covers giant tasks **12** (ProofOfTransferRecorder), **13** (RollbackManager), and **14** (retry-after-correction); requirements REQ-ARCH-006 (A/B), REQ-ARCH-007 (A/B), and REQ-ARCH-008 (A/B) destruction-with-proof. No new scope.
