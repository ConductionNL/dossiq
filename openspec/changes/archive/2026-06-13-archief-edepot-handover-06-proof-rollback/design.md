# Design: archief-edepot-handover-06-proof-rollback

## Scope

Per-case terminal handling: proof capture on success, rollback on rejection, retry-after-correction, and destruction-with-proof. Bulk orchestration and inspection export are member 07.

## Declarative-first (ADR-031)

Proof capture and rollback are imperative state-transition + side-effect work (attach read-only file, notify DIV, preserve SIP) with no declarative analogue — services are correct per ADR-031. The records they write (`ArchiefBewijs`, `OverdrachtTrigger` status) are declarative schema objects (member 01).

## Data access (ADR-001)

All reads/writes of `ArchiefBewijs`, `OverdrachtTransactie`, `SipBundel`, `OverdrachtTrigger`, `OverdrachtAuditLog` go through the OpenRegister ObjectService. No bespoke SQL.

## Service layout

### ProofOfTransferRecorder
- `createArchiefBewijs(caseId, archivId, receipt, eDepotName, ingestionDate)` — copy SIP checksums, store receipt, status `received`.
- `attachProofToCase(caseId, bewijsId)` — attach as a read-only file typed `ArchiefBewijs`; prevent modification/deletion.
- `verifyIntegrity(bewijsId, sipBundelId)` — compare `ArchiefBewijs` vs `SipBundel` checksums; alert DIV on mismatch.

### RollbackManager
- `onIngestionFailure(transactionId, errorCode, errorDetail)` — transaction `failed-final`, trigger `gefaald`, **case preserved unmodified**, SIP preserved, DIV task created, `submission-failed-rollback` logged.
- `recommendCorrectiveAction(errorCode, caseContext)` — map MDTO_VALIDATION_FAILED / CHECKSUM_MISMATCH / DOCUMENT_CONVERSION_FAILED / E_DEPOT_CAPACITY_EXCEEDED → actionable message.

### ArchivalRetryController
- `POST /api/archief/triggers/{triggerId}/retry` — re-bundle with current case state, re-submit, log old + new transactions; only allowed on `gefaald` triggers.

## Security (ADR-005)

The retry endpoint is the first user-facing HTTP surface in the chain. It MUST carry an explicit auth posture and an IDOR guard: the caller must be authorised for the case behind `triggerId`, and the endpoint validates the trigger is in `gefaald` before acting (no retrying arbitrary triggers). The proof attachment is written read-only so an authenticated user cannot tamper with an audit record. Rollback never deletes or mutates the case.

## Traceability

Giant Task 12 (proof), Task 13 (rollback), Task 14 (retry); REQ-ARCH-006-A/B, REQ-ARCH-007-A/B, REQ-ARCH-008-A/B.
