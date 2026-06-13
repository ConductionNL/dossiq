# Spec delta: archief-edepot-handover-06-proof-rollback

## ADDED Requirements

### Requirement: Successful transfer captures proof of transfer
The system MUST create an `ArchiefBewijs` on a successful e-Depot ingestion, attach it to the case as a read-only file, and store the original checksums for later verification.

#### Scenario: ArchiefBewijs created and attached read-only
- **GIVEN** an e-Depot returns success with an archief-id, a signed receipt and an ingestion timestamp
- **WHEN** the system processes the response
- **THEN** an `ArchiefBewijs` is created with the archivId, eDepotNaam, ingestionDatum, receipt and a copy of the SIP checksums, status `received`
- **AND** it is attached to the case as a read-only file typed `ArchiefBewijs` that cannot be modified or deleted
- **AND** an `OverdrachtAuditLog` `proof-captured` entry is recorded

### Requirement: Ingestion rejection rolls back without losing the dossier
The system MUST preserve the case in procest when the e-Depot rejects an ingestion, mark the trigger failed, and notify DIV with corrective action.

#### Scenario: MDTO validation failure rolls back cleanly
- **GIVEN** an e-Depot returns 400 with `errorCode` "MDTO_VALIDATION_FAILED" and a missing-field detail
- **WHEN** the failure handler runs
- **THEN** the `OverdrachtTransactie` status is `failed-final` with the errorCode and full response stored
- **AND** the `OverdrachtTrigger` status is `gefaald` and the `SipBundel` is preserved for diagnostics
- **AND** the case in procest is not deleted or modified and remains fully accessible
- **AND** DIV receives a task naming the error and the corrective steps

### Requirement: DIV can retry archival after correcting the case
The system MUST allow DIV to retry a failed archival, re-bundling with the current case state while preserving both transactions for audit.

#### Scenario: Retry after correction succeeds
- **GIVEN** a trigger in status `gefaald` whose underlying case has been corrected
- **WHEN** DIV calls `POST /api/archief/triggers/{triggerId}/retry` and is authorised for that case
- **THEN** a fresh `SipBundel` is built from the current case state and a new `OverdrachtTransactie` is submitted
- **AND** both the old failed and the new transactions are retained in `OverdrachtAuditLog`
- **AND** on success the trigger becomes `geslaagd` and an `ArchiefBewijs` is created
- **AND** the endpoint rejects retry on triggers that are not in status `gefaald`
