---
status: done
status-note: Reverse-synced 2026-06-13 from an archived fully-implemented change; capability code confirmed present on development. 2026-06-13 member 06 completed the genuinely-absent rollback/retry capability — RollbackManager (onIngestionFailure + retryAfterCorrection) and POST /api/archief/triggers/{triggerId}/retry (archief-role + per-trigger IDOR/state guards), reusing ArchivalTriggerService + ProofOfTransferService; PHPUnit + Newman cover it.
---
# archief-edepot-handover Specification

## Purpose
TBD - created by archiving change archief-edepot-handover-01-schema-config. Update Purpose after archive.
## Requirements
### Requirement: procest-archief register declares six archival schemas
The app MUST register a `procest-archief` register hosting six OpenRegister schemas — `BewaarTermijnRegel`, `OverdrachtTrigger`, `SipBundel`, `OverdrachtTransactie`, `ArchiefBewijs`, `OverdrachtAuditLog` — each with the documented fields and relations, importable idempotently on install.

#### Scenario: All six schemas registered on fresh install
- **GIVEN** a fresh procest instance with OpenRegister available
- **WHEN** the register/schema import runs on install
- **THEN** the `procest-archief` register exists
- **AND** all six schemas (`BewaarTermijnRegel`, `OverdrachtTrigger`, `SipBundel`, `OverdrachtTransactie`, `ArchiefBewijs`, `OverdrachtAuditLog`) exist and pass OpenRegister schema validation
- **AND** `OverdrachtTrigger` declares a relation to `case` via `zaakId`
- **AND** `OverdrachtTransactie` declares a relation to `SipBundel` via `sipBundelId`

#### Scenario: Generic REST endpoints available and empty pre-seed
- **GIVEN** the six schemas are registered
- **WHEN** the generic OpenRegister collection endpoint for `SipBundel` is queried before any object is written
- **THEN** the response is an empty collection
- **AND** re-running the schema import does not duplicate any schema

### Requirement: VNG default retention rules are seeded idempotently
The app MUST seed VNG-default `BewaarTermijnRegel` rows on first install so that common zaaktypen have a retention disposition out of the box, and the seed MUST be idempotent.

#### Scenario: Default rules present after first install
- **GIVEN** a fresh procest instance
- **WHEN** the retention-rule seed runs
- **THEN** a `BewaarTermijnRegel` exists for `omgevingsvergunning` with `bewaartermijnJaren` = 5 and `selectielijstCategorie` = "Selectielijst gemeenten 4.1.3"
- **AND** a rule exists for `wmo-aanvraag` with `bewaartermijnJaren` = 10
- **AND** a rule exists for `subsidie-verlening` with `bewaartermijnJaren` = "permanent"

#### Scenario: Re-running the seed does not duplicate rules
- **GIVEN** the default rules are already seeded
- **WHEN** the seed step runs a second time
- **THEN** the total count of seeded default `BewaarTermijnRegel` rows is unchanged
- **AND** a DIV admin can subsequently modify a seeded rule (e.g. 5 → 7 years) without the next seed run re-creating the original

### Requirement: Nightly detection assigns retention and marks ready cases
The system MUST run a nightly trigger-detection job that determines each closed case's retention period from its `BewaarTermijnRegel` and creates an `OverdrachtTrigger` with the correct `overdrachtDatum` and status.

#### Scenario: Ready and not-yet-due cases get correct triggers
- **GIVEN** rules exist for `omgevingsvergunning` (5yr) and `wmo-aanvraag` (10yr)
- **AND** a case of type `omgevingsvergunning` closed 2021-05-20 and a case of type `wmo-aanvraag` closed 2017-03-10
- **WHEN** the nightly detection job runs on 2026-05-22
- **THEN** the `omgevingsvergunning` case gets an `OverdrachtTrigger` with `overdrachtDatum` 2026-05-20 and status `gereed-voor-overdracht`
- **AND** the `wmo-aanvraag` case gets an `OverdrachtTrigger` with `overdrachtDatum` 2027-03-10 and status `gepland`

#### Scenario: Permanent retention marks ready without a date
- **GIVEN** a rule for `subsidie-verlening` with `bewaartermijnJaren` = "permanent"
- **AND** a closed case of that type
- **WHEN** detection runs
- **THEN** the trigger status is `gereed-voor-overdracht` with no calculated `overdrachtDatum`

### Requirement: Cases without a retention rule are blocked and DIV is notified
The system MUST mark a closed case whose zaaktype has no `BewaarTermijnRegel` as blocked and notify DIV with an actionable message.

#### Scenario: Missing rule blocks the trigger
- **GIVEN** a closed case of unknown type `custom-process` with no matching `BewaarTermijnRegel`
- **WHEN** detection runs
- **THEN** an `OverdrachtTrigger` is created with status `geblokkeerd-geen-regel`
- **AND** `redenBlokkering` = "Geen BewaarTermijnRegel geconfigureerd voor zaaktype 'custom-process'"
- **AND** a DIV medewerker receives a notification instructing them to configure a retentiebesluit for that zaaktype

### Requirement: Active bezwaar/beroep suspends the trigger
The system MUST suspend archival readiness while a case has an active legal procedure and resume it once the procedure ends.

#### Scenario: Suspended then resumed
- **GIVEN** a case with active bezwaar/beroep
- **WHEN** detection runs
- **THEN** the `OverdrachtTrigger` status is `opgeschort-juridische-procedure` with no `overdrachtDatum`
- **AND** when the bezwaar/beroep ends and detection re-runs, `overdrachtDatum` is calculated and status becomes `gereed-voor-overdracht`

### Requirement: Bundler produces XSD-valid MDTO/TMLO metadata
The system MUST generate a metadata bundle conforming to MDTO 1.1 (or TMLO 1.2.1, configurable per e-Depot) with all required and present-optional fields, validate it against the official XSD, and persist a `SipBundel`.

#### Scenario: Complete bundle validates against XSD
- **GIVEN** a ready-for-transfer case with titel, omschrijving, behandelaar, betrokken organisaties and 7 documents that all have a document-type
- **WHEN** the metadata bundler runs against an e-Depot configured for MDTO
- **THEN** a `SipBundel` is created with MDTO 1.1 XML containing identificatie, aggregatieniveau, naam, classificatie, dekkingInTijd, beperkingGebruik, bewaartermijn and eventGeschiedenis
- **AND** the XML validates against the official MDTO XSD
- **AND** `SipBundel.metadataXsdValid` = true
- **AND** an `OverdrachtAuditLog` entry records "MDTO bundeling voltooid; XSD validatie geslaagd"

#### Scenario: Per-document metadata included
- **GIVEN** a case with documents of types Beschikking, Situatietekening and Milieueffectrapport
- **WHEN** the bundler generates MDTO XML
- **THEN** each document's type code, author (if available), creation date, access restrictions and digital-signature metadata (if signed) are included in the XML

### Requirement: Missing document-type blocks bundling
The system MUST block bundling when any document lacks a document-type, create no partial `SipBundel`, and raise an actionable DIV task.

#### Scenario: One untyped document blocks the bundle
- **GIVEN** a case with 7 documents where one lacks a document-type
- **WHEN** the bundler runs
- **THEN** bundling is blocked and no `SipBundel` is created
- **AND** an `OverdrachtAuditLog` `bundling-failed` event is recorded
- **AND** a DIV task is raised: "Zaak [id] kan niet worden overgedragen; document '[filename]' heeft geen documenttype"
- **AND** the case remains `gereed-voor-overdracht` so bundling can be retried after correction

### Requirement: Each document is exported as PDF/A plus preserved original with checksums
The system MUST, for each case document, produce a PDF/A rendering and preserve the original file, computing a SHA-256 checksum for both variants.

#### Scenario: Mixed-format documents exported in pairs
- **GIVEN** a case containing a `.docx`, an `.xlsx` and a scanned `.pdf`
- **WHEN** the exporter runs
- **THEN** the `.docx` and `.xlsx` are converted to PDF/A-2b and the scanned `.pdf` is validated/re-rendered to PDF/A-2b
- **AND** both the original and the PDF/A variant are recorded for each document
- **AND** each variant has a SHA-256 checksum stored in `SipBundel.documents[]` with `format` "pdf/a" or "original"

#### Scenario: Digital signature preserved
- **GIVEN** a digitally signed `.pdf` document
- **WHEN** the exporter runs
- **THEN** the original is preserved with its signature intact
- **AND** the PDF/A rendering preserves signature metadata and includes a visual indicator that the document was digitally signed

### Requirement: Conversion failure blocks bundling with an actionable task
The system MUST block bundling when a document cannot be converted to PDF/A and notify DIV with corrective guidance.

#### Scenario: Corrupted document blocks the bundle
- **GIVEN** a document that fails PDF/A conversion (corrupted or unsupported)
- **WHEN** the exporter encounters the error
- **THEN** no partial `SipBundel` is finalised
- **AND** an `OverdrachtAuditLog` `bundling-failed` event with `errorCode` "DOCUMENT_CONVERSION_FAILED" is recorded
- **AND** a DIV task instructs replacing the file with a valid version and retrying

### Requirement: Batch document conversion respects a configurable rate limit
The system MUST support converting many documents concurrently up to a configurable limit with trackable progress.

#### Scenario: 20 documents converted with concurrency 4
- **GIVEN** 20 documents queued for export with `concurrencyLimit` = 4
- **WHEN** the batch export runs
- **THEN** at most 4 conversions run in parallel and the remaining 16 are queued
- **AND** progress (total, completed, inProgress, failed) is observable

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

### Requirement: DIV can run concurrent batch transfers with per-case reporting
The system MUST let DIV initiate a batch transfer of many cases with a configurable concurrency limit, track per-case status, and produce a batch report.

#### Scenario: Batch of 250 cases with concurrency 4
- **GIVEN** 250 cases in status `gereed-voor-overdracht` for the same e-Depot
- **WHEN** DIV calls `POST /api/archief/batch/initiate` with the case ids and `rateLimit` 4
- **THEN** a batch job is created and at most 4 cases are processed in parallel
- **AND** `GET /api/archief/batch/{jobId}` reports totalCases, completed, inProgress, pending, succeeded and failed with a failed-cases list

#### Scenario: Batch report after completion
- **GIVEN** a batch that completes with 245 succeeded and 5 failed
- **WHEN** DIV calls `GET /api/archief/batch/{jobId}/report`
- **THEN** a ZIP is returned with a per-case `summary.csv`, a `failed-cases.txt` with corrective actions, and a `batch-stats.txt` with totals and throughput

### Requirement: Annual inspection export produces an audit-grade ZIP
The system MUST generate an audit-grade export for a calendar year containing a CSV summary, per-case `ArchiefBewijs` PDFs, statistics, and a checksum-verification guide.

#### Scenario: Inspector requests the 2026 export
- **GIVEN** transferred cases for calendar year 2026
- **WHEN** an authorised inspector calls `POST /api/archief/inspection-export?year=2026`
- **THEN** a ZIP is returned with `archival-summary-2026.csv`, one `ArchiefBewijs-[zaak-id].pdf` per transferred case, a statistics PDF, and a checksum-verification guide

### Requirement: Archival events are queryable from an append-only audit log
The system MUST record archival milestones to the append-only `OverdrachtAuditLog` and expose them per case.

#### Scenario: Audit trail for a fully archived case
- **GIVEN** a case that went trigger-detected → bundling → submission → proof-captured
- **WHEN** an authorised caller queries `GET /api/archief/audit-log?zaakId={id}`
- **THEN** the response lists all events reverse-chronologically with timestamp, eventType, actor and details
- **AND** the events are immutable (append-only); the endpoint offers no mutation

### Requirement: DIV admin can manage retention rules through a validated UI
The system MUST let a DIV admin view, create, edit and delete `BewaarTermijnRegel` entries with validation, in Dutch and English.

#### Scenario: Create and edit a retention rule
- **GIVEN** a DIV admin on the retention-rule configuration screen
- **WHEN** they create a rule with zaaktypeKey, bewaartermijnJaren, selectielijstCategorie, eDepotBestemming and mdtoVersion
- **THEN** the rule is persisted and listed
- **AND** validation rejects a `bewaartermijnJaren` that is neither ≥ 1 nor "permanent"
- **AND** the admin can subsequently edit or delete the rule
- **AND** all visible labels are available in Dutch and English

### Requirement: DIV can monitor archival status on a dashboard
The system MUST present archival status (ready, in-progress, failed, completed, total transferred) and batch jobs with quick actions.

#### Scenario: Dashboard reflects current state
- **GIVEN** triggers and batch jobs in various states
- **WHEN** DIV opens the archival dashboard (`GET /api/archief/dashboard/stats`)
- **THEN** stat cards show counts for ready-for-transfer, in-progress, failed, completed and total transferred
- **AND** a triggers table and a batch-jobs table are shown with quick actions to initiate a batch, retry failed cases and view proof

### Requirement: The capability is covered by tests and operator documentation
The system MUST ship unit and end-to-end tests for the archival pipeline and admin/developer/e-Depot documentation.

#### Scenario: End-to-end workflow is tested and documented
- **GIVEN** the archival pipeline is implemented across the chain
- **WHEN** the test suite runs
- **THEN** an end-to-end happy-path test (trigger → bundle → submit → proof) and a failure-path test (failure → DIV notified → corrected → retry succeeds) both pass
- **AND** an admin guide, a developer guide and an e-Depot integration guide are present and describe setup, batch processing, failure handling and SIP/openconnector configuration

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

