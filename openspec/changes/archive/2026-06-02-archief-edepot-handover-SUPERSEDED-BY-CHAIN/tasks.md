# Tasks: archief-edepot-handover

Implementation tasks for archival handover to e-Depots, covering retention-rule configuration, trigger detection, metadata bundling, document export, submission, proof-of-transfer, rollback, batch processing, and inspection export.

---

## 1. Schema Registration & Retention Rule Configuration

### Task 1: Register procest-archief Schemas in OpenRegister

**Spec ref**: Design — Data Model
**Files**:
- `openspec/schemas/procest-archief/BewaarTermijnRegel.json`
- `openspec/schemas/procest-archief/OverdrachtTrigger.json`
- `openspec/schemas/procest-archief/SipBundel.json`
- `openspec/schemas/procest-archief/OverdrachtTransactie.json`
- `openspec/schemas/procest-archief/ArchiefBewijs.json`
- `openspec/schemas/procest-archief/OverdrachtAuditLog.json`

**Acceptance criteria**:
- All 6 schemas pass OpenRegister validation (JSON Schema + x-openregister extensions)
- Each schema has proper relations defined (e.g., OverdrachtTrigger → case via zaakId)
- REST endpoints auto-created: GET, POST, PUT, DELETE per schema
- Idempotent: re-registering same schema does not duplicate

- [ ] Author BewaarTermijnRegel schema with fields: zaaktypeKey, bewaartermijnJaren, selectielijstCategorie, selectielijstVersie, eDepotBestemming, eDepotConnectionId, mdtoVersion, uitzonderingen, isActive
- [ ] Author OverdrachtTrigger schema: zaakId, zaaktypeKey, afsluitingsDatum, bewaartermijnJaren, overdrachtDatum, status (enum), aanmeldingsDatum, redenBlokkering
- [ ] Author SipBundel schema: zaakId, metadataXml, metadataXsdVersion, metadataXsdValid, documents[], bundleFormat, manifestChecksum, bundleSize, status, createdAt, bundleContent
- [ ] Author OverdrachtTransactie schema: sipBundelId, eDepotConnectionId, eDepotNaam, submissionChannel, submissionTime, attemptNumber, httpStatus, responseBody, archivId, status, errorCode, errorDetail, nextRetryTime
- [ ] Author ArchiefBewijs schema: zaakId, archivId, eDepotNaam, ingestionDatum, ontvangstBevestiging, checksums, sipBundelId, status, createdAt
- [ ] Author OverdrachtAuditLog schema: triggerId, zaakId, eventType (enum), timestamp, actor, details, relatedId
- [ ] Validate all schemas against OpenRegister JSON Schema specification
- [ ] Register all 6 schemas in openregister via API or admin panel
- [ ] Verify REST endpoints are available: curl /api/openregister/BewaarTermijnRegel (returns empty list)

### Task 2: Seed Default Retention Rules (VNG Selectielijst Defaults)

**Spec ref**: REQ-ARCH-001, Design — Seed Data
**Files**:
- `lib/Migration/Seed/BewaarTermijnRegels.php` (or YAML seed file)

**Acceptance criteria**:
- VNG default rules for common zaaktypen are seeded on app installation
- Seed is idempotent (re-run does not duplicate rules)
- DIV admin can override defaults per organization
- Seed includes: omgevingsvergunning (5yr), wmo-aanvraag (10yr), subsidie-verlening (permanent), with proper selectielijst references

- [ ] Define seed data for BewaarTermijnRegel (5–10 common zaaktypen with VNG defaults)
- [ ] Implement migration/seeder class to load seed on first install
- [ ] Each rule includes: zaaktypeKey, bewaartermijnJaren, selectielijstCategorie, selectielijstVersie, mdtoVersion (default "1.1")
- [ ] Test: seed on fresh install creates 5–10 rules; rerun seed does not duplicate
- [ ] Test: DIV admin can modify seed rule (e.g., change 5 years → 7 years) without breaking subsequent runs
- [ ] Document: DIV instructions for adding custom zaaktype retention rules in admin panel

---

## 2. Retention Trigger Detection Daemon

### Task 3: Implement ArchivalTriggerDaemon Service

**Spec ref**: REQ-ARCH-001
**Files**:
- `lib/Service/ArchivalTriggerDaemon.php`
- `lib/Command/DetectArchivalReadyCommand.php` (Console command for manual testing)

**Acceptance criteria**:
- Daemon runs nightly at configured time (e.g., 03:00 UTC)
- Detects cases with `endDate + retention_years < today`
- Creates OverdrachtTrigger with status "ready-for-transfer"
- Blocks cases with missing BewaarTermijnRegel (status "geblokkeerd-geen-regel") and notifies DIV
- Handles active bezwaar/beroep: marks trigger as "opgeschort"

- [ ] Implement `detectReadyCases()` method:
  - Query all cases with endDate ≤ (today - min(retention_years))
  - For each case: lookup BewaarTermijnRegel by zaaktypeKey
  - If rule found: create/update OverdrachtTrigger with status "ready-for-transfer", calculate overdrachtDatum
  - If rule missing: create OverdrachtTrigger with status "geblokkeerd-geen-regel", set redenBlokkering message
  - If bezwaar/beroep active: mark trigger as "opgeschort-juridische-procedure", defer overdrachtDatum
- [ ] Implement `updateTriggerStatus(triggerId, newStatus)` to transition trigger states
- [ ] Implement `logEvent(triggerId, eventType, details)` to append to OverdrachtAuditLog
- [ ] Create console command: `php artisan archief:detect-ready` for manual testing
- [ ] Schedule daemon via CronJob or background-task system (Laravel scheduler or Nextcloud background jobs)
- [ ] Test: Run daemon on test data; verify triggers created for ready cases, blocked for missing rules
- [ ] Test: Bezwaar case remains "opgeschort"; re-check after bezwaar ends → status updates to "ready-for-transfer"
- [ ] Test: Nightly run on production data (dry-run first) with performance profiling (should complete in < 5 minutes for 10k cases)

### Task 4: DIV Notification on Blocked Triggers

**Spec ref**: REQ-ARCH-001-B
**Files**:
- `lib/Service/NotificationService.php` (add method)
- `lib/Notification/BlockedArchivalTriggerNotification.php`

**Acceptance criteria**:
- DIV medewerker receives email when case is blocked due to missing BewaarTermijnRegel
- Email includes zaak-id, zaaktype, and actionable instruction
- Task is automatically created (optional) for DIV to configure rule

- [ ] Implement `notifyBlockedTrigger(triggerId)` method
- [ ] Compose email template: "Zaak [id] kan niet worden overgedragen; configureer eerst BewaarTermijnRegel voor zaaktype '[type]'"
- [ ] Send email to configured DIV group (via openregister organization contact)
- [ ] Optionally create `task` entity with title "Configureer retentiebesluit voor zaaktype [type]"
- [ ] Test: Block a case; verify email received and task created

---

## 3. Metadata Bundling (TMLO/MDTO)

### Task 5: Implement MetadataBundler Service

**Spec ref**: REQ-ARCH-002
**Files**:
- `lib/Service/MetadataBundler.php`
- `lib/Metadata/TmloXmlBuilder.php` or `MdtoXmlBuilder.php`
- `tests/unit/MetadataBundlerTest.php`

**Acceptance criteria**:
- Generates TMLO 1.2.1 or MDTO 1.1 XML (configurable per e-Depot)
- XML validates against official XSD schema
- All required fields included; all optional present fields included
- SipBundel created with metadata XML stored (embedded or file reference)

- [ ] Implement `buildBundle(caseId, mdtoVersion)` method
  - Load case + zaak metadata from procest
  - Load BewaarTermijnRegel for zaaktype
  - Fetch case documents (caseDocument relations)
  - Generate MDTO/TMLO XML with all required + optional fields (identificatie, aggregatieniveau, naam, classificatie, dekkingInTijd, beperkingGebruik, bewaartermijn, eventGeschiedenis, author, etc.)
  - Return: {metadataXml, metadataXsdVersion, documents[]}
- [ ] Implement MDTO XML builder class:
  - Map procest case fields → MDTO XML elements per MDTO 1.1 spec
  - Handle multi-language fields (Dutch required, English optional)
  - Include document metadata per attachment (type, author, creation date, restrictions)
  - Handle document-type classification from procest documentType references
- [ ] Implement `validateXsd(xmlContent, xsdSchema)` method:
  - Load official MDTO 1.1 XSD (or TMLO 1.2.1 if fallback)
  - Validate XML against XSD using PHP XML validator
  - Return: {valid: boolean, errors: [list of validation errors]}
- [ ] Implement `createSipBundel(caseId, metadataXml, documents[])`:
  - Create SipBundel entity with metadataXml, documents array, status "prepared"
  - Store metadataXml as Nextcloud file (or embedded in record if small)
  - Return SipBundel ID for further processing
- [ ] Test: Bundle a test case; validate XML against MDTO XSD; verify all required fields present
- [ ] Test: Block bundling if document-type is missing; verify error message
- [ ] Test: Validate both MDTO 1.1 and TMLO 1.2.1 (configurable per rule)

### Task 6: XSD Validation and Error Handling

**Spec ref**: REQ-ARCH-002-B
**Files**:
- `lib/Metadata/SchemaValidator.php`
- Tests for validation error scenarios

**Acceptance criteria**:
- Missing document-type blocks bundling with clear error
- DIV receives actionable error message
- Bundling can be retried after correction

- [ ] Implement SchemaValidator with `validateDocumentTypes(caseId)` method
  - Check that every document in case has a documentType reference
  - Return: {valid: boolean, missingDocuments: [{id, filename, reason}]}
- [ ] In bundler: call validation before XML generation
- [ ] On validation failure:
  - Block SipBundel creation
  - Log error in OverdrachtAuditLog with eventType "bundling-failed"
  - Create DIV task: "Zaak [id] kan niet worden overgedragen; document '[filename]' ontbreekt documenttype; voeg documenttype toe en retry"
- [ ] Test: Missing document-type blocks bundling; error message guides to correction

---

## 4. Document Export (PDF/A + Originals + Checksums)

### Task 7: Implement DocumentExporter Service

**Spec ref**: REQ-ARCH-003
**Files**:
- `lib/Service/DocumentExporter.php`
- `lib/DocumentFormat/PdfAConverter.php` (wrapper around docudesk API)
- `lib/Util/ChecksumComputer.php`

**Acceptance criteria**:
- For each document: convert to PDF/A-2b/3a (via docudesk) AND preserve original
- Compute SHA-256 checksums for both variants
- Handle digitally signed documents (preserve signature metadata)
- Block bundling if conversion fails

- [ ] Implement `exportToFormatPair(documentId)` method:
  - Load document from procest
  - Call docudesk PDF/A conversion API (async or sync per docudesk capabilities)
  - If conversion succeeds: return {pdfA: {filePath, mimeType, fileSize}, original: {filePath, mimeType, fileSize}}
  - If conversion fails: raise ConversionException with error details
  - Handle digitally signed PDFs: preserve signature metadata in original, include signature indicator in PDF/A rendering
- [ ] Implement PdfAConverter wrapper:
  - Call docudesk via openconnector adapter or direct HTTP API
  - Handle async conversion (polling or webhook callback)
  - Timeout handling (fail after 5 minutes)
  - Retry logic for transient docudesk failures
- [ ] Implement `computeChecksum(filePath, algorithm='sha256')`:
  - Read file (or stream for large files)
  - Compute SHA-256 hash
  - Return hex string
- [ ] Implement conversion error handling:
  - Log error to OverdrachtAuditLog
  - Block SipBundel creation
  - Create DIV task with error details and correction instructions
- [ ] Test: Export .docx, .xlsx, .pdf with checksums; verify PDF/A-2b output
- [ ] Test: Digitally signed PDF preserves signature + adds visual indicator in PDF/A
- [ ] Test: Conversion failure (corrupted file) blocks bundling; error message guides to file replacement

### Task 8: Batch Document Conversion with Rate-Limiting

**Spec ref**: Design — Service Layout
**Files**:
- `lib/Service/DocumentExporter.php` (extend with concurrency control)
- Config: `config/archief.php` (add docudesk concurrency limit)

**Acceptance criteria**:
- Multiple documents can be converted in parallel (default 4 concurrent conversions)
- Rate limit is configurable per e-Depot or globally
- Progress is tracked; DIV can monitor conversion status

- [ ] Extend DocumentExporter to support batch export:
  - `exportDocumentsBatch(documentIds[], concurrencyLimit=4)` method
  - Queue documents for conversion
  - Maintain concurrency limit: spawn new conversion only when prior finishes
  - Track progress: {total, completed, inProgress, failed}
  - Return: {successes: [{docId, pdfA, original, checksums}], failures: [{docId, reason}]}
- [ ] Config: allow DIV to set concurrency limit (default 4 to avoid e-Depot/docudesk overload)
- [ ] Test: Convert 20 documents with concurrency=4; verify 4 in parallel, 16 queued
- [ ] Test: Conversion progress trackable via API endpoint

---

## 5. SIP Assembly (BagIt Packaging + Manifest)

### Task 9: Implement SIP Bundler (BagIt Format)

**Spec ref**: REQ-ARCH-004, Design — Data Model
**Files**:
- `lib/Service/BagItBundler.php`
- `lib/Util/BagItManifestBuilder.php`
- Tests for BagIt structure validation

**Acceptance criteria**:
- SipBundel packaged as BagIt (RFC 8493) directory structure
- Manifest (bagit-manifest-sha256.txt) generated with SHA-256 hashes for all files
- Total bundle checksum computed and stored
- Optional: tar/gzip compression for transport

- [ ] Implement `buildBagIt(sipBundelId)` method:
  - Create BagIt directory structure:
    ```
    [sipBundelId]/
    ├─ bagit.txt (BagIt declaration)
    ├─ bag-info.txt (metadata: zaakId, zaaktype, transfer-date, e-Depot)
    ├─ data/
    │  ├─ metadata.xml
    │  ├─ doc001-beschikking.pdf (PDF/A)
    │  ├─ doc001-beschikking.docx (original)
    │  ├─ ...
    └─ manifest/
       └─ manifest-sha256.txt
    ```
  - Load SipBundel record (metadata, documents array)
  - Write metadata.xml to data/
  - Link/copy document files to data/ (PDF/A and original pairs)
  - Generate manifest file with SHA-256 for each file
  - Compute total bundle checksum (SHA-256 of all file hashes)
  - Store BagIt path in SipBundel.bundleContent
  - Update SipBundel.status = "ready-for-submission"
- [ ] Implement BagItManifestBuilder:
  - Scan all files in data/
  - Compute SHA-256 for each
  - Write bagit-manifest-sha256.txt per RFC 8493
  - Return manifest content + total checksum
- [ ] Optional: Implement tar.gz compression:
  - After BagIt structure complete, tar + gzip for efficient transport
  - Store compressed path in SipBundel
- [ ] Test: Build BagIt for sample SipBundel; verify structure, manifest format, checksums
- [ ] Test: Re-compute manifest; verify checksums match (idempotent)
- [ ] Test: Tar/gzip compression optional; verify size reduction

---

## 6. e-Depot Submission

### Task 10: Implement EDepotSubmitter Service (HTTPS/SFTP/S3)

**Spec ref**: REQ-ARCH-005
**Files**:
- `lib/Service/EDepotSubmitter.php`
- `lib/EDepot/HttpsSubmitter.php`
- `lib/EDepot/SftpSubmitter.php`
- `lib/EDepot/S3Submitter.php`

**Acceptance criteria**:
- HTTPS POST submission with API-key authentication
- SFTP upload with SSH-key authentication
- S3 PUT with credentials authentication
- Archief-id extracted from e-Depot response
- OverdrachtTransactie recorded

- [ ] Implement `submitBundle(sipBundelId, eDepotConnectionId)` router method:
  - Load SipBundel (BagIt path, documents)
  - Load e-Depot connection config from openconnector
  - Route to appropriate submitter based on connectionType (HTTP, SFTP, S3)
  - Execute submission
  - Return: {status, archivId, receipt}
- [ ] Implement HttpsSubmitter:
  - POST SipBundel to configured endpoint (multipart/form-data or application/zip)
  - Include Authorization header (API-key or OAuth2 token from openconnector config)
  - Parse JSON/XML response for archief-id extraction
  - Return: {httpStatus, archivId, receipt}
- [ ] Implement SftpSubmitter:
  - Establish SFTP connection (SSH key from openconnector config)
  - Upload BagIt (tar.gz) to drop-folder
  - Write completion trigger file (.complete)
  - Poll completion-ack folder for response (with timeout)
  - Parse response for archief-id
  - Return: {status, archivId}
- [ ] Implement S3Submitter:
  - PUT BagIt to S3-compatible storage (e.g., Minio, AWS)
  - Use credentials from openconnector config
  - Optionally set metadata tags (zaakId, e-Depot-name)
  - Return: {status, objectUrl}
- [ ] Test: HTTPS submission with sample e-Depot; verify archief-id extracted
- [ ] Test: SFTP upload; verify completion trigger + response polling
- [ ] Test: S3 PUT; verify object created + accessible

### Task 11: Exponential Backoff Retry Logic

**Spec ref**: REQ-ARCH-005-C
**Files**:
- `lib/Service/SubmissionRetryDaemon.php`
- `lib/Command/RetryFailedSubmissionsCommand.php`

**Acceptance criteria**:
- Failed submissions retry with exponential backoff (1m, 5m, 30m, 2h, 8h)
- Each retry creates new OverdrachtTransactie.attemptNumber
- After 5 failures: escalate to DIV (no further auto-retry)
- Audit trail logs each retry

- [ ] Implement `SubmissionRetryDaemon.processRetryQueue()`:
  - Query OverdrachtTransactie with status "retrying" and nextRetryTime ≤ now
  - For each: re-execute submitBundle()
  - On success: update status to "succeeded"; create ArchiefBewijs
  - On failure: increment attemptNumber; calculate nextRetryTime per backoff schedule; update status (if attempt < 5: "retrying"; if attempt = 5: "failed-final")
  - If attempt = 5: escalate to DIV (task + email)
  - Log each retry to OverdrachtAuditLog
- [ ] Implement backoff schedule:
  - Attempt 1: nextRetryTime = now + 1 min
  - Attempt 2: nextRetryTime = now + 5 min
  - Attempt 3: nextRetryTime = now + 30 min
  - Attempt 4: nextRetryTime = now + 2 hr
  - Attempt 5: nextRetryTime = now + 8 hr
  - Attempt 6+: escalate (no auto-retry)
- [ ] Create console command: `php artisan archief:retry-submissions` for manual testing
- [ ] Schedule daemon to run every 5 minutes (or via background-job system)
- [ ] Test: Simulate failed submission; verify retry after 1 min; retry after 5 min; etc.
- [ ] Test: After 5 failures, DIV receives escalation task

---

## 7. Proof of Transfer

### Task 12: Implement ProofOfTransferRecorder

**Spec ref**: REQ-ARCH-006
**Files**:
- `lib/Service/ProofOfTransferRecorder.php`
- `lib/Model/ArchiefBewijsRecorder.php`

**Acceptance criteria**:
- ArchiefBewijs created on successful e-Depot submission
- Attached to case as read-only file
- Checksums verified and stored
- DIV can retrieve proof for inspection export

- [ ] Implement `createArchiefBewijs(caseId, archivId, receipt, eDepotName, ingestionDate)` method:
  - Create ArchiefBewijs record in openregister
  - Copy SipBundel checksums to ArchiefBewijs.checksums
  - Store receipt document (PDF + signature)
  - Set status = "received"
  - Return ArchiefBewijs ID
- [ ] Implement `attachProofToCase(caseId, bewijsId)`:
  - Load case entity
  - Attach ArchiefBewijs as read-only file to case.files
  - Mark file with type="ArchiefBewijs" for identification
  - Prevent modification/deletion of proof
- [ ] Implement `verifyIntegrity(bewijsId, sipBundelId)`:
  - Load ArchiefBewijs checksums
  - Load SipBundel original checksums
  - Compare; return {valid: boolean, mismatches: [...]}
  - If mismatch: alert DIV (should not happen in normal operation; indicates e-Depot or transit corruption)
- [ ] Test: Create ArchiefBewijs on successful submission; verify attached to case
- [ ] Test: Retrieve proof; verify receipt PDF accessible + metadata intact
- [ ] Test: Checksum verification; simulate mismatch and verify alert

---

## 8. Rollback on Failure

### Task 13: Implement RollbackManager

**Spec ref**: REQ-ARCH-007
**Files**:
- `lib/Service/RollbackManager.php`
- `lib/ErrorHandler/ArchivalErrorHandler.php`

**Acceptance criteria**:
- Failed ingestion triggers rollback
- Case remains in procest (not deleted)
- OverdrachtTrigger marked "gefaald"
- DIV notified with corrective action
- Retry possible after correction

- [ ] Implement `onIngestionFailure(transactionId, errorCode, errorDetail)` method:
  - Load OverdrachtTransactie + SipBundel + case
  - Update OverdrachtTransactie.status = "failed-final"
  - Update OverdrachtTrigger.status = "gefaald"
  - Preserve SipBundel (do not delete; needed for diagnostics)
  - Create DIV task with error details + corrective action
  - Log to OverdrachtAuditLog: {eventType: "submission-failed-rollback", errorCode, details}
  - Return: {status: "rolled-back", actionRequired: "..."}
- [ ] Implement `recommendCorrectiveAction(errorCode, caseContext)`:
  - Map error codes to actionable instructions:
    - "MDTO_VALIDATION_FAILED" + field → "Update missing field X in zaak"
    - "CHECKSUM_MISMATCH" → "Retry submission (files may have corrupted in transit)"
    - "DOCUMENT_CONVERSION_FAILED" → "Replace document with valid version; retry bundling"
    - "E_DEPOT_CAPACITY_EXCEEDED" → "Contact e-Depot; retry after capacity increase"
  - Return: instructional message for DIV
- [ ] Implement DIV task creation:
  - Title: "Zaak [id] overdracht mislukt: [errorCode]"
  - Description: error details + corrective action steps
  - Assigned to: configured DIV group
  - Link to: SipBundel (for inspection) + case (for correction)
- [ ] Test: Simulate e-Depot rejection; verify case remains accessible, DIV notified with correction steps
- [ ] Test: DIV corrects case; retry succeeds and completes archival

### Task 14: Implement Retry-After-Correction Flow

**Spec ref**: REQ-ARCH-007-B
**Files**:
- `lib/Controller/ArchivalRetryController.php`

**Acceptance criteria**:
- DIV can retry archival via `POST /api/archief/triggers/{triggerId}/retry`
- New bundling + submission with current case state
- All transactions logged for audit trail

- [ ] Implement `retryArchival(triggerId)` endpoint (POST):
  - Load OverdrachtTrigger
  - Fetch current case state (may have been corrected)
  - Initiate new metadata bundling (fresh SipBundel)
  - Submit new bundle to e-Depot
  - Log both old-failed and new transactions to OverdrachtAuditLog
  - Return: {status, newTransactionId}
- [ ] Validate: only allow retry on failed triggers (status = "gefaald")
- [ ] Test: Fail archival due to missing field; correct field; retry; succeed
- [ ] Test: Audit trail shows both failed and successful transactions

---

## 9. Bulk Batch Processing

### Task 15: Implement ArchivalBatchProcessor

**Spec ref**: REQ-ARCH-009
**Files**:
- `lib/Service/ArchivalBatchProcessor.php`
- `lib/Model/ArchivalBatchJob.php`
- `lib/Controller/BatchArchivalController.php`

**Acceptance criteria**:
- DIV can initiate batch-transfer of 100s of cases
- Concurrent bundling/submission with configurable rate-limit
- Per-case status tracking
- Error reporting with retry capability
- Batch report generation (CSV + stats)

- [ ] Implement `initiateBatch(caseIds[], rateLimit=4, eDepotId=null)` method:
  - Create ArchivalBatchJob record
  - Queue all cases for processing
  - Set concurrency limit
  - Return job ID
- [ ] Implement batch job state machine:
  - `state = "queued"` → `"processing"` → `"completed"` (or `"partially-failed"` if some failed)
  - Track: totalCases, completed, inProgress, pending, succeeded, failed
- [ ] Implement `processCaseInBatch(caseId)`:
  - Bundling (metadata + documents)
  - Submission to e-Depot
  - Proof-capture or rollback
  - Update batch job progress
  - Do NOT block on case failure; continue with next case
- [ ] Implement concurrency control:
  - Maintain queue of pending cases
  - Spawn up to `rateLimit` processing tasks in parallel
  - As tasks complete, spawn next pending case
  - Progress callback for status updates
- [ ] Implement batch status API: `GET /api/archief/batch/{jobId}`:
  - Return: {totalCases, completed, inProgress, pending, succeeded, failed, failedCases: [{caseId, reason}]}
- [ ] Implement batch report API: `GET /api/archief/batch/{jobId}/report`:
  - Generate CSV: zaak-id, zaaktype, afsluiting-datum, status, archiv-id, errorCode
  - Generate text summary: totals, throughput, failed cases with details
  - Download ZIP
- [ ] Test: Initiate batch of 250 cases with rateLimit=4; verify 4 processed in parallel, progress trackable
- [ ] Test: Batch completes with 245 success + 5 failures; report generated correctly

### Task 16: DIV Batch Initiation UI / Endpoint

**Spec ref**: REQ-ARCH-009-A
**Files**:
- `src/views/archief/BatchInitiator.vue` (or equivalent in admin panel)
- `lib/Controller/BatchArchivalController.php`

**Acceptance criteria**:
- DIV can select multiple cases (filter by status "ready-for-transfer", zaaktype, e-Depot)
- DIV can set rate-limit
- Batch initiated via API call
- Progress monitoring dashboard available

- [ ] Implement `POST /api/archief/batch/initiate` endpoint:
  - Payload: {caseIds[], eDepotId, rateLimit}
  - Validation: all cases exist and status = "ready-for-transfer"
  - Response: {jobId, totalCases, expectedDuration}
- [ ] Implement batch initiation UI (optional):
  - Case selector (filter by criteria)
  - Checkbox to select cases
  - Rate-limit slider
  - "Start Batch" button → calls POST /api/archief/batch/initiate
  - Progress dashboard: real-time updates (totalCases, completed, inProgress, failed)
  - Failed cases list with error details + retry button
- [ ] Test: Initiate batch via API; monitor progress; complete batch successfully

---

## 10. Inspection Export & Audit Trail

### Task 17: Implement Inspection Export API

**Spec ref**: REQ-ARCH-010
**Files**:
- `lib/Service/InspectionExportService.php`
- `lib/Controller/InspectionExportController.php`

**Acceptance criteria**:
- DIV/inspector can request audit export for specified year
- Export includes: CSV summary, ArchiefBewijs PDFs, statistics, checksum guide
- Delivered as ZIP

- [ ] Implement `generateInspectionExport(year, filters={})` method:
  - Query all OverdrachtTrigger with status "geslaagd" for specified year
  - For each: retrieve ArchiefBewijs, build CSV row
  - Collect all ArchiefBewijs PDFs
  - Generate statistics PDF: total transferred, success rate, avg processing time, failed cases (if any)
  - Generate checksum-verification guide for e-Depot contacts
  - Package all into ZIP
  - Return ZIP file path
- [ ] Implement CSV schema:
  - Columns: zaak-id, zaaktype, closed-date, transfer-date, archiv-id, e-depot-name, success/failed-status
  - Row per transferred case
- [ ] Implement statistics PDF:
  - Chart: transfer count by zaaktype
  - Summary: total, success rate, avg processing time, failed count
  - List of failed cases (if any) with error codes + resolution status
- [ ] Implement checksum guide:
  - Instructions per e-Depot (how to request checksum verification)
  - Sample checksum format
  - Contact info for e-Depot support
- [ ] Implement `GET /api/archief/inspection-export?year=2026` endpoint
- [ ] Test: Generate export for 2026; verify CSV, PDFs, stats, guide included; download ZIP

### Task 18: Archival Audit Trail (OverdrachtAuditLog)

**Spec ref**: Design — Data Model, REQ-ARCH-010-B
**Files**:
- Logging throughout Tasks 3–17

**Acceptance criteria**:
- All archival events logged to OverdrachtAuditLog (append-only)
- Inspector can query audit trail for a case
- Events include: trigger-detected, bundling, submission, failure, rollback, proof-capture, retry

- [ ] Define event types for logging:
  - `trigger-detected` — Retention period reached; trigger created
  - `bundling-started`, `bundling-succeeded`, `bundling-failed` — Metadata bundling
  - `submission-initiated`, `submission-succeeded`, `submission-failed-retry`, `submission-failed-final` — e-Depot submission
  - `rollback-executed` — Failure rollback
  - `proof-captured`, `proof-verified` — ArchiefBewijs creation + verification
  - `zaak-vernietigd-post-archival`, `zaak-vernietigd-ohne-archival` — Destruction
  - `batch-initiated`, `batch-completed` — Bulk processing
- [ ] Throughout codebase: call `ArchivalAuditTrail.logEvent(triggerId, eventType, timestamp, actor, details)` at each milestone
- [ ] Implement `GET /api/archief/audit-log?zaakId={id}` endpoint:
  - Return: list of events for zaak, reverse-chronological
  - Include: timestamp, eventType, actor, details
- [ ] Test: Perform complete archival workflow (trigger → bundle → submit → proof); verify all events in audit log
- [ ] Test: Inspector can query audit log; events are immutable (append-only)

---

## 11. Configuration & Admin Panel

### Task 19: Retention Rule Configuration UI

**Spec ref**: Design — Service Layout
**Files**:
- `src/views/settings/tabs/ArchivalRulesTab.vue` (or admin panel integration)
- `lib/Controller/ArchivalRuleController.php`

**Acceptance criteria**:
- DIV admin can view, create, edit, delete BewaarTermijnRegel
- Form includes: zaaktypeKey, bewaartermijnJaren, selectielijstCategorie, eDepotBestemming, mdtoVersion
- Validation: retention years > 0 or "permanent"

- [ ] Implement `GET /api/archief/rules` endpoint (list all rules)
- [ ] Implement `POST /api/archief/rules` (create new rule)
- [ ] Implement `PUT /api/archief/rules/{ruleId}` (update rule)
- [ ] Implement `DELETE /api/archief/rules/{ruleId}` (delete rule)
- [ ] Create admin UI (optional, can use openregister generic forms):
  - List of rules
  - Add button → form dialog (zaaktypeKey, bewaartermijnJaren, selectielijstCategorie, eDepotBestemming, mdtoVersion)
  - Edit button → pre-fill form; update on save
  - Delete button with confirmation
- [ ] Validation: zaaktypeKey must be valid zaaktype in system; bewaartermijnJaren ≥ 1 or "permanent"
- [ ] Test: Create, edit, delete rules via API + UI

### Task 20: Dashboard & Monitoring

**Spec ref**: Design — Service Layout
**Files**:
- `src/views/archief/ArchivalDashboard.vue`
- `lib/Controller/ArchivalDashboardController.php`

**Acceptance criteria**:
- DIV can view real-time archival status
- Display: triggers ready-for-transfer, in-progress, failed; batch job progress
- Quick actions: initiate batch, retry failed, view proof

- [ ] Implement `GET /api/archief/dashboard/stats` endpoint:
  - {ready: count, inProgress: count, failed: count, completed: count, totalTransferred: count}
- [ ] Implement dashboard UI (optional):
  - Stats cards: Ready for Transfer, In Progress, Failed, Completed, Total Transferred
  - Triggers table (filterable, sortable): zaak-id, zaaktype, overdracht-datum, status, action
  - Batch jobs table: jobId, totalCases, completed, failed, initiated-date
  - Quick actions: "Initiate Batch", "View Failed Cases", "Retry All Failed"
  - Links: View trigger details, View audit log, View ArchiefBewijs
- [ ] Test: Dashboard displays current state; stats update in real-time (or on refresh)

---

## 12. Testing & Documentation

### Task 21: Unit & Integration Tests

**Spec ref**: All tasks
**Files**:
- `tests/unit/Service/ArchivalTriggerDaemonTest.php`
- `tests/unit/Service/MetadataBundlerTest.php`
- `tests/unit/Service/DocumentExporterTest.php`
- `tests/unit/Service/EDepotSubmitterTest.php`
- `tests/unit/Service/ProofOfTransferRecorderTest.php`
- `tests/unit/Service/RollbackManagerTest.php`
- `tests/unit/Service/ArchivalBatchProcessorTest.php`
- `tests/Feature/ArchivalWorkflowTest.php` (end-to-end)

**Acceptance criteria**:
- All major services have unit tests
- End-to-end workflow tested (trigger → bundle → submit → proof)
- Error paths tested (missing field, conversion failure, e-Depot rejection, retry)
- Concurrency/batch processing tested

- [ ] Unit tests:
  - ArchivalTriggerDaemon: detect ready cases, block missing rules, handle bezwaar
  - MetadataBundler: generate valid MDTO XML, block missing document-type
  - DocumentExporter: export PDF/A + original, compute checksums, handle conversion failure
  - BagItBundler: create BagIt structure, generate manifest, compute bundle checksum
  - EDepotSubmitter: submit via HTTPS/SFTP, parse archief-id, handle auth
  - SubmissionRetryDaemon: exponential backoff, escalation after 5 failures
  - ProofOfTransferRecorder: create ArchiefBewijs, attach to case, verify checksums
  - RollbackManager: rollback on failure, recommend corrective action
  - ArchivalBatchProcessor: initiate batch, concurrency control, per-case tracking
- [ ] Integration tests:
  - End-to-end: case closed → trigger detected → bundled → submitted → proof captured
  - Error path: bundling fails → DIV notified → DIV corrects → retry succeeds
  - Batch: initiate 50 cases, 4 concurrent, all succeed or fail with report
- [ ] Mocking/Fixtures:
  - Mock docudesk API (PDF/A conversion)
  - Mock e-Depot endpoints (HTTPS, SFTP, S3)
  - Mock case/document entities (test data)
  - Mock Nextcloud file storage
- [ ] Coverage goal: > 80% of archival code paths

- [ ] Write unit tests for ArchivalTriggerDaemon
- [ ] Write unit tests for MetadataBundler (MDTO XML generation + validation)
- [ ] Write unit tests for DocumentExporter (PDF/A conversion, checksums)
- [ ] Write unit tests for BagItBundler (structure, manifest, checksums)
- [ ] Write unit tests for EDepotSubmitter (HTTPS, SFTP, S3, archief-id extraction)
- [ ] Write unit tests for SubmissionRetryDaemon (backoff, escalation)
- [ ] Write unit tests for RollbackManager (rollback, error messages)
- [ ] Write unit tests for ArchivalBatchProcessor (concurrency, progress)
- [ ] Write end-to-end integration test: complete archival workflow
- [ ] Write end-to-end integration test: failure path with retry
- [ ] Run all tests; achieve > 80% code coverage
- [ ] Document test data fixtures (sample cases, documents, rules)

### Task 22: Documentation

**Spec ref**: All tasks
**Files**:
- `docs/archief/README.md` (overview)
- `docs/archief/admin-guide.md` (DIV setup + operations)
- `docs/archief/developer-guide.md` (architecture, extension points)
- `docs/archief/edepot-integration.md` (e-Depot adapter configuration)

**Acceptance criteria**:
- DIV admin can understand retention rules, batch processing, failure handling
- Developers can understand architecture, extend e-Depot adapters, customize workflows
- e-Depot operators can understand API expectations, integration points

- [ ] Write admin guide:
  - Overview: what is archival, why it matters, legal requirements
  - Retention rule setup: configure BewaarTermijnRegel per zaaktype
  - Batch transfer: how to initiate, monitor, handle failures
  - Proof of transfer: where to find ArchiefBewijs, how to share with inspectors
  - Troubleshooting: common errors + remedies
  - Contacts: DIV team, e-Depot support info, Nationaal Archief
- [ ] Write developer guide:
  - Architecture overview (diagram)
  - Service layer design
  - Extension points: custom e-Depot adapters, metadata transformations, notification handlers
  - API reference: endpoints + examples
  - Schema definitions: BewaarTermijnRegel, OverdrachtTrigger, etc.
  - Testing: how to test with mock e-Depot
- [ ] Write e-Depot integration guide:
  - For e-Depot operators: expected SIP format (BagIt + MDTO XML), submission endpoints, response format
  - How to configure openconnector adapter
  - Checksum verification procedures
  - Troubleshooting: what to do if checksum mismatch, validation errors
- [ ] Include examples: sample MDTO XML, sample BagIt structure, sample API calls, sample error responses

- [ ] Author admin guide (overview, setup, batch processing, troubleshooting)
- [ ] Author developer guide (architecture, extension points, API reference, testing)
- [ ] Author e-Depot integration guide (SIP format, openconnector config, verification)
- [ ] Include architecture diagrams (workflow, data model, system integration)
- [ ] Include code examples and sample data
- [ ] Ensure documentation is accessible to both technical and non-technical users
