# Spec delta: archief-edepot-handover-05-sip-submission

## ADDED Requirements

### Requirement: SIP is packaged as BagIt with a SHA-256 manifest
The system MUST package the metadata XML and document files into a BagIt (RFC 8493) structure with a SHA-256 manifest and a total bundle checksum.

#### Scenario: BagIt manifest generated for a ready bundle
- **GIVEN** a `SipBundel` with metadata XML and document PDF/A + original files
- **WHEN** the BagIt bundler runs
- **THEN** a BagIt structure is created with `bagit.txt`, `bag-info.txt`, a `data/` directory and a `manifest-sha256.txt` listing a SHA-256 hash per file
- **AND** a total bundle checksum is computed and stored in `SipBundel.manifestChecksum`
- **AND** `SipBundel.status` becomes `ready-for-submission`

### Requirement: SIP is submitted to the configured e-Depot channel with authentication
The system MUST submit the SIP to the e-Depot over the configured channel (HTTPS POST, SFTP upload, or S3 PUT) using the connection's authentication and record an `OverdrachtTransactie`.

#### Scenario: HTTPS POST with API-key returns an archief-id
- **GIVEN** an e-Depot openconnector connection with connectionType "HTTP", an ingest endpoint and an API-key
- **WHEN** the SIP is submitted
- **THEN** a POST is sent with an `Authorization: ApiKey …` header and the SIP body
- **AND** on a success response the archief-id is extracted into `OverdrachtTransactie.archivId` and the transaction status is `succeeded`

#### Scenario: SFTP drop-folder upload with completion trigger
- **GIVEN** an e-Depot connection with connectionType "SFTP", a drop-folder and a completion-file convention
- **WHEN** the SIP is submitted
- **THEN** the BagIt tar.gz is uploaded and a `.complete` trigger file is written
- **AND** the polling daemon reads the acknowledgement and extracts the archief-id into the transaction

### Requirement: Failed submissions retry with exponential backoff and escalate after five attempts
The system MUST retry failed submissions with exponential backoff and escalate to DIV after five attempts, recording each attempt.

#### Scenario: Backoff schedule and escalation
- **GIVEN** a submission that fails with a network error
- **WHEN** the retry daemon processes it
- **THEN** retries are scheduled at 1 minute, 5 minutes, 30 minutes, 2 hours and 8 hours
- **AND** each attempt creates a new `OverdrachtTransactie` with an incremented `attemptNumber` and an `OverdrachtAuditLog` `submission-failed-retry` entry
- **AND** after the fifth failure the case is escalated to DIV with no further automatic retry
