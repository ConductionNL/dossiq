# Design: archief-edepot-handover

## Architecture

The archival handover capability is layered on top of procest (case engine), openregister (schema + REST), openconnector (e-Depot adapter), and docudesk (format conversion). It does NOT fork case or document management; instead:

1. Monitors case closure dates via a retention-trigger daemon
2. Detects when `endDate + retention_years ≤ today` via OverdrachtTrigger entities
3. Orchestrates metadata bundling (TMLO/MDTO) + document export (PDF/A + originals) → SipBundel
4. Routes bundles to configured e-Depot via openconnector adapters
5. Captures proof-of-transfer as ArchiefBewijs (read-only audit record)
6. Rolls back on failure: keeps dossier in procest, notifies DIV with actionable error

```
Procest                         Archive Register
├─ case entity                 ├─ BewaarTermijnRegel (config)
│  ├─ endDate                  ├─ OverdrachtTrigger (detector)
│  └─ documents[]              ├─ SipBundel (package)
├─ auditTrail                  ├─ OverdrachtTransactie (submission)
└─ (no modifications)          ├─ ArchiefBewijs (proof)
                               └─ OverdrachtAuditLog (events)

Docudesk (format service)      OpenConnector (e-Depot adapters)
├─ PDF/A conversion            ├─ HTTPS POST endpoint
├─ Document metadata extraction├─ SFTP drop-folder
└─ Checksum computation        └─ S3-compatible storage

Archival Daemon                DIV Admin UI
├─ Nightly trigger detection   ├─ Retention rule config
├─ Metadata bundling           ├─ Batch transfer initiation
├─ e-Depot submission          ├─ Status monitoring
└─ Rollback on failure         └─ Proof-of-transfer export
```

## Service Layout

### ArchivalTriggerDaemon
- **Responsibility**: Runs nightly; identifies cases with `endDate + retention_years ≤ today`; creates/updates OverdrachtTrigger records
- **Methods**: 
  - `detectReadyCases()` — Query cases by zaaktype; calculate overdracht-datum; create OverdrachtTrigger if not exists
  - `updateTriggerStatus(triggerId, status)` — Transition trigger through states: planned → ready-for-transfer → in-transfer → succeeded → failed/destroyed
  - `logEvent(triggerId, eventType, details)` — Append to OverdrachtAuditLog

### MetadataBundler (TMLO/MDTO)
- **Responsibility**: Builds TMLO/MDTO-compliant XML + document exports
- **Methods**:
  - `buildBundle(caseId, mdtoVersion)` — Load case + docs; fetch BewaarTermijnRegel; generate MDTO XML (all required + optional fields)
  - `validateXsd(xmlContent, xsdSchema)` — Validate XML against TMLO/MDTO XSD
  - `exportDocuments(caseId)` — For each document: request PDF/A conversion, preserve original, compute checksums
  - `createSipBundel(caseId, metadataXml, documents[], manifest)` — Package into SipBundel entity

### DocumentExporter
- **Responsibility**: Converts documents to PDF/A; preserves originals; computes checksums
- **Methods**:
  - `exportToFormatPair(documentId)` — → {pdfA, original}; handles digitally signed docs (preserve signature metadata)
  - `computeChecksum(filePath, algorithm='sha256')` — → hex string
  - `handleConversionFailure(documentId, error)` — Log error; block bundling; notify DIV

### EDepotSubmitter
- **Responsibility**: Routes SipBundel to configured e-Depot via openconnector; handles retries and auth
- **Methods**:
  - `submitBundle(sipBundelId, eDepotConnectionId)` — Load e-Depot config from openconnector; route to appropriate transport (HTTPS/SFTP/S3)
  - `retryWithBackoff(transactionId, attempt)` — Exponential backoff: 1min, 5min, 30min, 2hr, 8hr; escalate after 5 failures
  - `parseArchivIdResponse(response, format)` — Extract archief-id from different e-Depot response formats
  - `recordTransaction(transactionId, status, archivId, receipt)` → OverdrachtTransactie + ArchiefBewijs

### RollbackManager
- **Responsibility**: Handles ingestion failures; rolls back state; notifies DIV
- **Methods**:
  - `onIngestionFailure(transactionId, errorCode, errorDetail)` — Update OverdrachtTrigger status to "failed"; preserve SipBundel for diagnostics; craft DIV notification
  - `recommendCorrectiveAction(errorCode, caseContext)` — → actionable fix message (e.g., "Field X missing in case; update and retry")

### ProofOfTransferRecorder
- **Responsibility**: Captures and stores ArchiefBewijs (proof-of-transfer)
- **Methods**:
  - `createArchiefBewijs(caseId, archivId, receipt, eDepotName, ingestionDate)` — Create ArchiefBewijs record
  - `attachProofToCase(caseId, bewijsId)` — Attach as read-only document to case.files
  - `verifyIntegrity(bewijsId, checksums)` — Verify ArchiefBewijs checksums match original SipBundel

### ArchivalBatchProcessor
- **Responsibility**: Orchestrates bulk archival of 100s of cases with rate-limiting and reporting
- **Methods**:
  - `initiateBatch(caseIds[], rateLimit=4)` — Create batch job; queue cases for parallel bundling/submission
  - `processCaseInBatch(caseId)` — Bundling → submission → proof-capture → error handling (serial within case, parallel across cases)
  - `generateBatchReport(batchJobId)` → {totalCases, succeeded, failed, failureDetails[], stats}

### ArchivalAuditTrail
- **Responsibility**: Append-only event log for all archival operations
- **Methods**:
  - `logEvent(triggerId, eventType, timestamp, actor, details)` → OverdrachtAuditLog entry
  - **Event types**: trigger-detected, bundling-started, bundling-succeeded, bundling-failed, submission-initiated, submission-succeeded, submission-failed, rollback-executed, proof-captured

## Data Model

### Six New OpenRegister Schemas

#### BewaarTermijnRegel (Retention Rule Configuration)
- **Purpose**: Per-zaaktype retention period + e-Depot destination configuration
- **Fields**:
  - `zaaktypeKey` (string, required) — Zaaktype identifier (e.g., "omgevingsvergunning")
  - `bewaartermijnJaren` (integer or "permanent" string) — Retention in years OR permanent
  - `selectielijstCategorie` (string) — Selectielijst category (e.g., "Selectielijst gemeenten 4.1.3")
  - `selectielijstVersie` (string) — Selectielijst version (e.g., "2020")
  - `eDepotBestemming` (string) — Name of configured e-Depot (e.g., "RHC Utrecht")
  - `eDepotConnectionId` (string) — Reference to openconnector connection
  - `mdtoVersion` (enum) — "1.2.1" (TMLO legacy) or "1.1" (MDTO current)
  - `uitzonderingen` (array) — Special cases (e.g., {condition: "actief bezwaar", delayUntil: "bezwaar afgerond"})
  - `isActive` (boolean) — Enable/disable rule
- **Relations**: none (lookup table, not reference-heavy)

#### OverdrachtTrigger (Archival Readiness Detector)
- **Purpose**: Record of when a case becomes eligible for archival
- **Fields**:
  - `zaakId` (string, required) — Reference to case
  - `zaaktypeKey` (string) — Cached zaaktype for query efficiency
  - `afsluitingsDatum` (date) — Case `endDate`
  - `bewaartermijnJaren` (integer or "permanent" string) — Applied retention period
  - `overdrachtDatum` (date) — Calculated = afsluitingsDatum + bewaartermijnJaren
  - `status` (enum) — gepland, gereed-voor-overdracht, in-overdracht, geslaagd, gefaald, vernietigd
  - `aanmeldingsDatum` (datetime) — When trigger was created (or recalculated if rule changed)
  - `redenBlokkering` (string, optional) — If status = "geblokkeerd-geen-regel": reason (e.g., "Geen BewaarTermijnRegel voor [zaaktype]")
- **Relations**: → case

#### SipBundel (Submission Information Package)
- **Purpose**: Container for metadata + documents ready for e-Depot submission
- **Fields**:
  - `zaakId` (string, required) — Reference to case
  - `metadataXml` (text) — TMLO/MDTO-compliant XML (embedded or file reference)
  - `metadataXsdVersion` (string) — MDTO version used (1.2.1 or 1.1)
  - `metadataXsdValid` (boolean) — XSD validation result
  - `documents` (array) — [{documentId, format (pdf/a or original), fileName, fileSize, checksumSha256}]
  - `bundleFormat` (enum) — "BagIt" (RFC 8493) or "ToPX-XML"
  - `manifestChecksum` (string, hex) — SHA-256 of entire bundle manifest
  - `bundleSize` (integer bytes) — Total size for transport
  - `status` (enum) — prepared, ready-for-submission, submitted, failed, archived
  - `createdAt` (datetime)
  - `bundleContent` (string/reference) — Packaged BagIt or ToPX (may be external file store)
- **Relations**: → case

#### OverdrachtTransactie (e-Depot Submission Transaction)
- **Purpose**: Record of a submission attempt to e-Depot
- **Fields**:
  - `sipBundelId` (string, required) — Reference to SipBundel
  - `eDepotConnectionId` (string) — openconnector connection used
  - `eDepotNaam` (string) — Display name of e-Depot
  - `submissionChannel` (enum) — "HTTPS_POST", "SFTP_UPLOAD", "S3_PUT"
  - `submissionTime` (datetime) — When submission was initiated
  - `attemptNumber` (integer) — Retry attempt (1, 2, 3, ...)
  - `httpStatus` (integer, optional) — HTTP response code (200, 400, 500, etc.)
  - `responseBody` (text, optional) — Full e-Depot response for diagnostics
  - `archivId` (string, optional) — Extracted archief-id from successful response
  - `status` (enum) — "pending", "submitted", "succeeded", "failed", "retrying"
  - `errorCode` (string, optional) — e-Depot error classification (e.g., "MDTO_VALIDATION_FAILED", "CHECKSUM_MISMATCH")
  - `errorDetail` (text, optional) — Detailed error message from e-Depot
  - `nextRetryTime` (datetime, optional) — Scheduled time for next retry (exponential backoff)
- **Relations**: → SipBundel

#### ArchiefBewijs (Proof of Transfer)
- **Purpose**: Audit-grade proof that a case was successfully archived
- **Fields**:
  - `zaakId` (string, required) — Reference to case
  - `archivId` (string, required) — Unique archival ID from e-Depot
  - `eDepotNaam` (string) — Certified archive name (e.g., "RHC Utrecht e-Depot")
  - `ingestionDatum` (datetime) — When e-Depot ingested the bundle
  - `ontvangstBevestiging` (string/reference) — Signed receipt document (PDF + XML signature, if supported)
  - `checksums` (object) — Copy of original SipBundel checksums for verification: {bundleChecksum, documentChecksums[]}
  - `sipBundelId` (string) — Reference to original SipBundel (for integrity checks)
  - `status` (enum) — "received", "verified", "retained"
  - `createdAt` (datetime)
- **Relations**: → case, → SipBundel

#### OverdrachtAuditLog (Archival Event Stream)
- **Purpose**: Immutable, append-only event log for complete archival transaction history
- **Fields**:
  - `triggerId` (string, required) — Reference to OverdrachtTrigger
  - `zaakId` (string, required) — Reference to case (denormalized for query)
  - `eventType` (enum) — "trigger-detected", "bundling-started", "bundling-succeeded", "bundling-failed", "submission-initiated", "submission-succeeded", "submission-failed-retry", "submission-failed-final", "rollback-executed", "proof-captured", "proof-verified"
  - `timestamp` (datetime) — Immutable creation time
  - `actor` (string, optional) — User/daemon that triggered event (system for daemon, user for manual retry)
  - `details` (object, JSON) — Event-specific context (e.g., {errorCode, retryAttempt, eDepotName})
  - `relatedId` (string, optional) — sipBundelId or transactionId if applicable
- **Relations**: → OverdrachtTrigger, → case (denormalized)

### Relationships

```
case
 ├→ BewaarTermijnRegel (via zaaktypeKey match)
 ├→ OverdrachtTrigger (1:1, created when ready)
 │  ├→ SipBundel (1:many, multiple bundlings on retry)
 │  │  └→ OverdrachtTransactie (1:many, multiple submission attempts)
 │  │     └→ ArchiefBewijs (1:1 if succeeded)
 │  └→ OverdrachtAuditLog (1:many, all events)
 └→ ArchiefBewijs (read-only attachment via case.files)
```

## Seed Data

### BewaarTermijnRegel (3 retention rules per org, VNG defaults)

**Omgevingsvergunning**
- zaaktypeKey: "omgevingsvergunning"
- bewaartermijnJaren: 5
- selectielijstCategorie: "Selectielijst gemeenten 4.1.3"
- eDepotBestemming: "RHC Utrecht e-Depot"
- mdtoVersion: "1.1"

**WMO-aanvraag**
- zaaktypeKey: "wmo-aanvraag"
- bewaartermijnJaren: 10
- selectielijstCategorie: "Selectielijst gemeenten 5.2.1"
- eDepotBestemming: "Regionaal Archief Zuid-Holland"
- mdtoVersion: "1.1"

**Subsidie-verlening**
- zaaktypeKey: "subsidie-verlening"
- bewaartermijnJaren: "permanent"
- selectielijstCategorie: "Selectielijst gemeenten 3.4.2"
- eDepotBestemming: "RHC Utrecht e-Depot"
- mdtoVersion: "1.1"

### OverdrachtTrigger (3 example cases)

**Case 1 — Omgevingsvergunning, ready**
- zaakId: "2026-ENV-001" (closed 2021-05-20)
- zaaktypeKey: "omgevingsvergunning"
- afsluitingsDatum: "2021-05-20"
- bewaartermijnJaren: 5
- overdrachtDatum: "2026-05-20"
- status: "ready-for-transfer" (as of 2026-05-22)
- aanmeldingsDatum: "2026-05-22T08:15:00Z"

**Case 2 — WMO-aanvraag, not yet due**
- zaakId: "2026-WMO-001" (closed 2017-03-10)
- zaaktypeKey: "wmo-aanvraag"
- afsluitingsDatum: "2017-03-10"
- bewaartermijnJaren: 10
- overdrachtDatum: "2027-03-10"
- status: "planned"
- aanmeldingsDatum: "2026-05-22T08:15:00Z"

**Case 3 — Unknown zaaktype, blocked**
- zaakId: "2026-UNK-001"
- zaaktypeKey: "unknown-process-type"
- afsluitingsDatum: "2025-05-22"
- bewaartermijnJaren: null
- status: "geblokkeerd-geen-regel"
- redenBlokkering: "Geen BewaarTermijnRegel geconfigureerd voor zaaktype 'unknown-process-type'"

### SipBundel (example bundled case)

**Bundle for 2026-ENV-001**
- zaakId: "2026-ENV-001"
- metadataXml: (MDTO 1.1 XML, ~5KB)
- metadataXsdValid: true
- documents: [
    {documentId: "doc-001", format: "pdf/a", fileName: "beschikking.pdf", checksumSha256: "abc123..."},
    {documentId: "doc-001", format: "original", fileName: "beschikking.docx", checksumSha256: "def456..."},
    {documentId: "doc-002", format: "pdf/a", fileName: "situatietekening.pdf", checksumSha256: "ghi789..."},
    {documentId: "doc-002", format: "original", fileName: "situatietekening.dwg", checksumSha256: "jkl012..."}
  ]
- bundleFormat: "BagIt"
- manifestChecksum: "xyz999..."
- bundleSize: 45780
- status: "ready-for-submission"
- createdAt: "2026-05-22T09:30:00Z"

## API Design

### Administrative Configuration

- `GET /api/archief/retention-rules` — List all BewaarTermijnRegel (admin)
- `POST /api/archief/retention-rules` — Create new rule
- `PUT /api/archief/retention-rules/{ruleId}` — Update rule (versioning handled by openregister)
- `GET /api/archief/triggers?status=ready-for-transfer` — List OverdrachtTrigger by status
- `GET /api/archief/triggers/{triggerId}` — Retrieve trigger details + related audit log

### Batch Processing

- `POST /api/archief/batch/initiate` — Payload: {caseIds[], rateLimit, eDepotId} → jobId
- `GET /api/archief/batch/{jobId}` — Monitor batch progress (totalCases, succeeded, failed, inProgress)
- `GET /api/archief/batch/{jobId}/report` — Download batch report (CSV + error summaries)

### Proof of Transfer / Inspection Export

- `GET /api/archief/proof/{caseId}` — Retrieve ArchiefBewijs for case (proof-of-transfer)
- `POST /api/archief/inspection-export?year=2026` — Generate audit export for year; return ZIP with CSV, PDFs, checksum guide

### Event Stream & Diagnostics

- `GET /api/archief/audit-log?zaakId={id}` — Retrieve OverdrachtAuditLog events for a case
- `GET /api/archief/bundle/{sipBundelId}` — Inspect SipBundel contents (diagnostics after failure)

## Reuse Analysis

| Component | Used For | Source |
|-----------|----------|--------|
| `case` entity | Trigger source; reference in all archive records | Procest (existing) |
| `document` entity | Document export source; attachment target | Procest (existing) |
| `auditTrail` | Event sourcing for archive events | Procest built-in (existing) |
| `openregister` REST API | CRUD for all 6 archive schemas | openregister (existing) |
| `DocumentExporter` | PDF/A conversion, checksum | docudesk (existing service, called via API) |
| `openconnector` | e-Depot endpoint routing (HTTPS, SFTP, S3 adapters) | openconnector (existing) |
| `file storage` | Storing SipBundel + ArchiefBewijs receipts | Nextcloud (existing) |
| `task` schema | Optional: create DIV action task on failure | Procest (existing) |

No duplication found. All archival-specific services are orchestration + format transformation; reuse of existing procest, openregister, docudesk, openconnector capabilities.

## Integration Boundaries

- **Procest ↔ Archive Register** — All archival state managed in procest-archief register; procest case entity unchanged (read-only from archive perspective)
- **Procest ↔ openconnector** — e-Depot endpoints configured as openconnector sources; archive service uses openconnector routing to submit
- **Archive Register ↔ docudesk** — Async API call to convert documents to PDF/A; polling or webhook for result
- **Archive Register ↔ Nextcloud** — Receipts + SipBundles stored as Nextcloud files via openregister file attachment mechanism
- **DIV UI ↔ Archive API** — Admin dashboard and batch processor call archival REST endpoints; no direct register manipulation

## Standards Alignment

- **Archiefwet 1995** — Retention-period calculation per Selectielijst gemeenten 2020
- **Archiefwet 2024** — Future compatibility; prepared for MDTO adoption
- **TMLO 1.2.1** — Legacy metadata format supported (configurable per e-Depot)
- **MDTO 1.1** — Current standard; default for new bundling
- **ISO 14721 OAIS** — SipBundel structure inspired by OAIS information package model
- **BagIt RFC 8493** — Default SIP serialization format
- **ISO 19005 PDF/A** — Document format for long-term readability
- **Common Ground** — Data remains at source (procest) until archival moment; archival is centralized, delegated service
