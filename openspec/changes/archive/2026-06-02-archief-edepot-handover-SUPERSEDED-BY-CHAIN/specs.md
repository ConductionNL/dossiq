# Specs: archief-edepot-handover

## Overview

Detailed requirements for automated archival handover to e-Depots, covering retention rules, metadata bundling, document export, submission, proof-of-transfer, rollback, bulk processing, and audit exports.

---

## REQ-ARCH-001: Retention-Period Determination per Zaaktype

**Purpose**: System MUST determine retention period for each closed case based on zaaktype and configured BewaarTermijnRegel.

### REQ-ARCH-001-A: Rule-Based Retention Assignment

GIVEN a municipality has BewaarTermijnRegel entries:
- "omgevingsvergunning" → 5 years, "Selectielijst gemeenten 4.1.3"
- "wmo-aanvraag" → 10 years, "Selectielijst gemeenten 5.2.1"  
- "subsidie-verlening" → permanent, "Selectielijst gemeenten 3.4.2"

WHEN nightly trigger-detection job runs on 2026-05-22
THEN for each closed case:
- Case type "omgevingsvergunning" closed 2021-05-20 → OverdrachtTrigger created with overdrachtDatum = 2026-05-20 (≤ today), status = "ready-for-transfer"
- Case type "wmo-aanvraag" closed 2017-03-10 → OverdrachtTrigger with overdrachtDatum = 2027-03-10 (> today), status = "planned"
- Case type "subsidie-verlening" closed 2010-06-01 → OverdrachtTrigger with overdrachtDatum = "permanent" (no date), status = "ready-for-transfer"

### REQ-ARCH-001-B: Blocking on Missing Rule

GIVEN a zaak of unknown type "custom-process" is closed on 2026-05-01
WHEN nightly detection runs
THEN OverdrachtTrigger is created with status = "geblokkeerd-geen-regel"
AND redenBlokkering = "Geen BewaarTermijnRegel geconfigureerd voor zaaktype 'custom-process'"
AND DIV medewerker receives warning email: "Zaak [id] kan niet worden overgedragen; configureer eerst een retentiebesluit voor zaaktype 'custom-process' in Selectielijst"

### REQ-ARCH-001-C: Handling Active Bezwaar/Beroep

GIVEN a case has active bezwaar/beroep status
WHEN retention-period calculation runs
THEN OverdrachtTrigger.status = "opgeschort-juridische-procedure"
AND OverdrachtTrigger.overdrachtDatum is NOT calculated until bezwaar/beroep-status = "afgerond"
AND system re-checks case on next nightly run; if bezwaar ended, overdrachtDatum is calculated and status → "ready-for-transfer"

---

## REQ-ARCH-002: TMLO/MDTO Metadata Bundling

**Purpose**: System MUST produce metadata bundles fully conforming to TMLO 1.2.1 or MDTO 1.0/1.1 (configurable per e-Depot).

### REQ-ARCH-002-A: Complete XML Validation

GIVEN a zaak with:
- titel, omschrijving, behandelaar, betrokken organisaties
- 7 documenten met vastgestelde document-types

WHEN metadata bundler runs
THEN SipBundel is created with:
- MDTO 1.1 XML (if e-Depot destination configured for MDTO)
- XML validates successfully against official MDTO XSD schema
- ALL required fields present:
  - identificatie (unique zaak ID)
  - aggregatieniveau (document level)
  - naam (zaak title)
  - classificatie (selectielijst code)
  - dekkingInTijd (coverage dates)
  - beperkingGebruik (usage restrictions)
  - bewaartermijn (retention from rule)
  - eventGeschiedenis (closure event)
- ALL present optional fields included (author, milieueffectrapport, conditions, etc.)
- SipBundel.metadataXsdValid = true
- Response audit-log entry: "MDTO bundeling voltooid; XSD validatie geslaagd"

### REQ-ARCH-002-B: Blocking on Missing Document Type

GIVEN a zaak with 7 documents where 1 lacks a configured document-type
WHEN bundler runs
THEN bundling is BLOCKED (no partial bundle created)
AND error is raised: "Bundeling geblokkeerd: document [filename] ontbreekt document-type classificatie"
AND SipBundel is NOT created
AND DIV medewerker receives actionable task: "Zaak [id] kan niet worden overgedragen; document '[filename]' heeft geen documenttype. Voer een type in (bijv. 'Beschikking', 'Situatietekening', 'Milieueffectrapport') en retry bundeling."
AND case remains in "ready-for-transfer" status; bundling can be retried after correction

### REQ-ARCH-002-C: Document-Type Metadata Inclusion

GIVEN a zaak with documents of types: "Beschikking" (1), "Situatietekening" (1), "Milieueffectrapport" (1)
WHEN bundler generates MDTO XML
THEN for EACH document:
- Document-type code is included in MDTO XML
- Author name (if available) is included
- Creation date is included
- Access restrictions (if any) are marked in XML
- Digital signature metadata is preserved (if signed)

---

## REQ-ARCH-003: Multi-Format Document Export

**Purpose**: For each document, MUST produce both PDF/A rendering (long-term readability) AND original file (bit-perfect preservation).

### REQ-ARCH-003-A: Format Conversion

GIVEN a zaak containing:
- `.docx` file (MS Word)
- `.xlsx` file (MS Excel)
- Scanned `.pdf` file

WHEN bundler exports documents
THEN:
- `.docx` → LibreOffice headless or docudesk → PDF/A-2b file
- `.xlsx` → docudesk PDF/A conversion
- Scanned `.pdf` → validated against PDF/A-2b; if non-compliant, re-rendered to PDF/A-2b
- BOTH original AND PDF/A versions included in SipBundel
- Checksums (SHA-256) computed for both variants
- SipBundel.documents[n] = {documentId, format: "pdf/a", checksumSha256: "..."} and {documentId, format: "original", checksumSha256: "..."}

### REQ-ARCH-003-B: Conversion Failure Handling

GIVEN a document-conversion to PDF/A fails (e.g., corrupted .docx, unsupported format)
WHEN bundler encounters error
THEN:
- Bundling is BLOCKED; no partial SipBundel created
- Error is logged in OverdrachtAuditLog: {eventType: "bundling-failed", errorCode: "DOCUMENT_CONVERSION_FAILED", details: {documentId, reason}}
- DIV medewerker receives actionable task: "Zaak [id] bundeling mislukt: document '[filename]' kan niet naar PDF/A worden geconverteerd. Controleer het bestand en vervang het door een geldige versie (bijv. hermaken of scannen als PDF), dan retry."

### REQ-ARCH-003-C: Digital Signature Preservation

GIVEN a document is digitally signed (e.g., `.pdf` with embedded signature)
WHEN bundler exports
THEN:
- Original `.pdf` is included WITH signature intact
- PDF/A-2b rendering is created, preserving signature metadata
- PDF/A rendering includes visual indicator (e.g., annotation: "Dit document was digitaal ondertekend met handtekening [date]")
- Signature information is stored in MDTO XML metadata
- On e-Depot retrieval, both original (with signature) and rendering are accessible

---

## REQ-ARCH-004: Checksum Verification and BagIt Conformity

**Purpose**: SIP MUST contain SHA-256 checksums for every file; manifest MUST be re-verifiable by e-Depot.

### REQ-ARCH-004-A: Manifest Generation

GIVEN a SipBundel is closed (all files added, ready for submission)
WHEN manifest is generated
THEN:
- SHA-256 hash computed for EACH file (metadata XML, each document PDF/A and original, manifest itself)
- Hashes written to `bagit-manifest-sha256.txt` (BagIt standard format):
  ```
  abc123... data/metadata.xml
  def456... data/doc001-beschikking.pdf
  ghi789... data/doc001-beschikking.docx
  ...
  ```
- Total bundle checksum (SHA-256 of all file checksums combined) computed and stored in SipBundel.manifestChecksum
- SipBundel.status = "ready-for-submission"
- Audit-log entry: "Bundeling voltooid; checksums berekend; totaal bundle-checksum: xyz999..."

### REQ-ARCH-004-B: Checksum Mismatch Detection

GIVEN e-Depot receives SipBundel and re-computes checksums
WHEN e-Depot detects mismatch (file corrupted in transit)
THEN e-Depot returns response with:
- HTTP 400 or 422
- errorCode = "CHECKSUM_MISMATCH"
- errorDetail = "File data/doc001-beschikking.pdf checksum mismatch: expected abc123..., got def456..."

WHEN archive system receives this response
THEN:
- OverdrachtTransactie.status = "failed-checksum-mismatch"
- OverdrachtTransactie.errorCode = "CHECKSUM_MISMATCH"
- SipBundel.status remains "ready-for-submission" (preserved for retry)
- New submission is queued with exponential backoff (retry with fresh SipBundel transfer)
- OverdrachtAuditLog entry: {eventType: "submission-failed-retry", errorCode: "CHECKSUM_MISMATCH", nextRetryTime}

---

## REQ-ARCH-005: e-Depot Ingestion via Configured Channel

**Purpose**: System MUST submit SipBundel to configured e-Depot via supported transport (HTTPS/SFTP/S3) with authentication.

### REQ-ARCH-005-A: HTTPS POST with API-Key

GIVEN e-Depot is configured in openconnector:
- connectionType = "HTTP"
- endpoint = "https://edepot.example.com/api/ingest"
- authType = "api-key"
- apiKey = "secret-key-xyz"

WHEN SipBundel is submitted
THEN:
- POST request sent to configured endpoint
- Authorization header: `Authorization: ApiKey secret-key-xyz`
- Body: SipBundel (multipart/form-data or application/zip, per endpoint spec)
- System parses response for archief-id:
  - e-Depot response: `{status: "success", archivId: "EDP-2026-ABC-12345", receipt: "..."}`
  - OverdrachtTransactie.archivId = "EDP-2026-ABC-12345"
  - OverdrachtTransactie.status = "succeeded"

### REQ-ARCH-005-B: SFTP Drop-Folder Upload

GIVEN e-Depot is configured for SFTP:
- connectionType = "SFTP"
- host = "sftp.edepot.example.com"
- dropFolder = "/incoming/"
- completionFile = ".complete"

WHEN SipBundel is submitted
THEN:
- SFTP connection established (with SSH key auth from openconnector credentials)
- SipBundel uploaded to `/incoming/[zaakId]_[timestamp].bagit.tar.gz`
- After successful upload, trigger file `.complete` written: `/incoming/[zaakId]_[timestamp].bagit.tar.gz.complete`
- OverdrachtTransactie records: {submissionChannel: "SFTP_UPLOAD", uploadPath, submitTime}
- Polling daemon monitors `/incoming/acknowledged/` for response file containing archief-id
- On receipt: OverdrachtTransactie.archivId extracted; status = "succeeded"

### REQ-ARCH-005-C: Network Failure and Retry Strategy

GIVEN OverdrachtTransactie submission encounters network error (connection timeout, 503 service unavailable)
WHEN failure is detected
THEN:
- Retry scheduled with exponential backoff:
  - Attempt 1: retry after 1 minute
  - Attempt 2: retry after 5 minutes
  - Attempt 3: retry after 30 minutes
  - Attempt 4: retry after 2 hours
  - Attempt 5: retry after 8 hours
  - After attempt 5: escalate to DIV medewerker (no further auto-retry)
- Each attempt creates new OverdrachtTransactie.attemptNumber entry
- OverdrachtAuditLog records each retry: {eventType: "submission-failed-retry", attemptNumber, nextRetryTime}
- OverdrachtTrigger remains status "in-overdracht" during retry window
- If any attempt succeeds: OverdrachtTrigger → "geslaagd"
- If all 5 attempts fail: OverdrachtTrigger → "gefaald"; DIV notified

---

## REQ-ARCH-006: Proof-of-Transfer Capture

**Purpose**: On success, MUST create ArchiefBewijs record with archief-id + signed receipt for audit trail.

### REQ-ARCH-006-A: ArchiefBewijs Creation

GIVEN e-Depot returns success with:
- archief-id = "EDP-2026-ABC-12345"
- Signed receipt document (PDF + XML signature)
- Ingestion timestamp: 2026-05-22T14:30:00Z
- e-Depot name: "RHC Utrecht e-Depot"

WHEN system processes successful submission response
THEN ArchiefBewijs record is created with:
- zaakId = original case ID
- archivId = "EDP-2026-ABC-12345"
- eDepotNaam = "RHC Utrecht e-Depot"
- ingestionDatum = 2026-05-22T14:30:00Z
- ontvangstBevestiging = (receipt PDF stored)
- checksums = (copy of SipBundel.documents[n].checksumSha256 array)
- sipBundelId = (reference to SipBundel)
- status = "received"
- ArchiefBewijs is attached to case as read-only file: case.files.push({type: "ArchiefBewijs", fileId, metadata: {archivId, eDepotNaam}})
- Audit-log entry: {eventType: "proof-captured", archivId, eDepotNaam}

### REQ-ARCH-006-B: Proof Export for Archival Inspection

GIVEN archival inspector requests proof-of-transfer for case "2026-ENV-001"
WHEN inspector calls `GET /api/archief/proof/2026-ENV-001`
THEN response includes:
- ArchiefBewijs details: {archivId, eDepotNaam, ingestionDatum}
- Ontvangstbevestiging PDF (downloadable)
- OverdrachtAuditLog events (trigger-detected, bundling, submission, proof-captured)
- Original SipBundel checksums
- Instruction document: "Hoe checksums verifiëren met [e-Depot name]: [e-Depot-specific verification steps]"

GIVEN archival inspector requests audit export for year 2026
WHEN inspector calls `POST /api/archief/inspection-export?year=2026`
THEN system generates ZIP containing:
- CSV summary: zaak-id, zaaktype, afsluiting-datum, overdracht-datum, archief-id, e-Depot, ingestion-datum
- Individual ArchiefBewijs PDFs for each transferred case
- Aggregated statistics PDF: totaal-overgedragen, gefaald, in-behandeling, gemiddelde-verwerkingstijd
- Checksum-verification guide for e-Depot spot-checks

---

## REQ-ARCH-007: Rollback on Ingestion Failure

**Purpose**: If e-Depot rejects ingestion, MUST preserve dossier in procest + notify DIV with corrective action.

### REQ-ARCH-007-A: Failure Capture and Rollback

GIVEN e-Depot returns 400 with errorCode = "MDTO_VALIDATION_FAILED" and details:
```json
{
  "status": "error",
  "errorCode": "MDTO_VALIDATION_FAILED",
  "details": "Verplicht veld 'dekkingInTijd' ontbreekt in zaak-metadata"
}
```

WHEN system processes this failure
THEN:
- OverdrachtTransactie.status = "failed-final"
- OverdrachtTransactie.errorCode = "MDTO_VALIDATION_FAILED"
- OverdrachtTransactie.errorDetail = full e-Depot response
- SipBundel.status remains "ready-for-submission" (preserved for diagnostics; can inspect what was sent)
- OverdrachtTrigger.status = "gefaald"
- **Case in procest is NOT deleted or modified**; case remains fully accessible
- DIV medewerker receives task/email:
  ```
  Zaak [id] bundeling afgewezen door e-Depot
  Foutcode: MDTO_VALIDATION_FAILED
  Details: Verplicht veld 'dekkingInTijd' ontbreekt in zaak-metadata
  
  Actie:
  1. Open zaak [id] in procest
  2. Controleer veld 'Dekking in Tijd' (zaak-afsluiting-datum en oorspronggebeurtenissen)
  3. Zorg ervoor alle velden volledig zijn
  4. In archief-dashboard: klik "Retry overdracht" om opnieuw in te dienen
  ```

### REQ-ARCH-007-B: Retry After Correction

GIVEN DIV medewerker has corrected the missing field in the case
WHEN DIV initiates retry via `POST /api/archief/triggers/{triggerId}/retry`
THEN:
- New metadata bundling runs (fetches updated case data)
- New SipBundel is created (with corrected metadata)
- New OverdrachtTransactie is submitted
- OverdrachtAuditLog maintains both failed and new successful transactions (full traceability)
- If new submission succeeds: OverdrachtTrigger.status = "geslaagd"; ArchiefBewijs created
- Old failed OverdrachtTransactie remains in history for audit trail

---

## REQ-ARCH-008: Destruction After Transfer or Retention Expiry

**Purpose**: After successful transfer + retention period, MUST support case data destruction with proof.

### REQ-ARCH-008-A: Destruction Post-Archival

GIVEN a zaak successfully archived on 2026-01-15
AND procest-internal retention period configured = 6 months
WHEN nightly destruction job runs on 2026-07-15
THEN:
- Case data and all documents in procest are destroyed (soft-delete or permanent purge per policy)
- ArchiefBewijs and pointer to e-Depot archief-id are preserved as stub-record (case no longer fully accessible, but proof exists)
- OverdrachtAuditLog entry: {eventType: "zaak-vernietigd-post-archival", timestamp, archivId}
- Audit trail shows: "Zaakgegevens verwijderd uit procest op [date]; gearchiveerd bij e-Depot [eDepotName] onder archief-id [id]"

### REQ-ARCH-008-B: Destruction Without Archival (Short-Retention Cases)

GIVEN a zaak with bewaartermijn = "vernietigen na 1 jaar" (short-retention, no archival)
WHEN retention period expires on 2027-05-22 (1 year after closure)
THEN:
- Case is deleted from procest WITHOUT submitting to e-Depot
- Vernietigingsbewijs (destruction certificate) is generated with:
  - Zaak-id, title, afsluiting-datum, vernietigings-datum
  - Selectielijst categoria (documenting legal basis for destruction)
  - Signature/audit record
- DIV medewerker receives notification for control: "Zaak [id] is verwijderd conform retentiebesluit (vernietigen na 1 jaar). Controleer en onderteken vernietigingsbewijs."
- Vernietigingsbewijs filed permanently per archival law requirements

---

## REQ-ARCH-009: Bulk Batch Transfer Actions

**Purpose**: DIV staff MUST be able to trigger bulk archival of 100s of cases grouped by retention period.

### REQ-ARCH-009-A: Batch Initiation

GIVEN DIV medewerker identifies 250 cases with status "ready-for-transfer" for same e-Depot ("RHC Utrecht e-Depot")
WHEN DIV initiates batch via `POST /api/archief/batch/initiate` with payload:
```json
{
  "caseIds": ["2026-ENV-001", "2026-ENV-002", ..., "2026-WMO-250"],
  "eDepotId": "rhc-utrecht",
  "rateLimit": 4
}
```

THEN:
- Batch job is created (ID: "batch-2026-05-22-001")
- Status monitoring available: `GET /api/archief/batch/batch-2026-05-22-001`
- System queues cases for processing with concurrency limit = 4:
  - 4 cases bundled/submitted in parallel
  - Next 4 queued when first batch completes
- Response shows: {jobId, totalCases: 250, expectedDuration: "~8 hours", status: "processing"}

### REQ-ARCH-009-B: Batch Progress and Error Reporting

GIVEN batch is in progress
WHEN DIV checks status via `GET /api/archief/batch/batch-2026-05-22-001`
THEN response includes:
- {totalCases: 250, completed: 120, inProgress: 4, pending: 126, succeeded: 115, failed: 5}
- Failed cases list: [{caseId, errorCode, errorDetail}, ...]
- Retry capability: DIV can `POST .../batch-2026-05-22-001/retry` to auto-retry all failed cases with fresh bundling

GIVEN batch completes with 245 success + 5 failures
WHEN DIV downloads batch report via `GET /api/archief/batch/batch-2026-05-22-001/report`
THEN ZIP contains:
- `summary.csv` — per-case status: zaak-id, zaaktype, afsluiting-datum, status (success/failed), archiv-id (if success), errorCode (if failed)
- `failed-cases.txt` — detailed error descriptions for 5 failed cases with corrective actions
- `batch-stats.txt` — totals, timing, throughput (cases/hour), retry recommendation

---

## REQ-ARCH-010: Archival Inspection Export (Compliance Reporting)

**Purpose**: System MUST produce audit-grade exports for provincial archival inspectors.

### REQ-ARCH-010-A: Annual Inspection Export

GIVEN provincial archival inspector requests archival compliance report for municipality for calendar year 2026
WHEN inspector calls `POST /api/archief/inspection-export?year=2026`
THEN system generates ZIP with:
- `archival-summary-2026.csv` — columns: zaak-id, zaaktype, closed-date, transfer-date, archiv-id, e-depot-name, success/failure-status
- Individual `ArchiefBewijs-[zaak-id].pdf` files (one per transferred case)
- `batch-statistics.pdf` — Total cases transferred (by zaaktype), success rate, average processing time, list of failed cases with resolution status
- Compliance validation report: "250 zaken overgedragen aan RHC Utrecht; 100% MDTO 1.1 validatie geslaagd; nul checksum-mismatches; nul e-Depot rejections"

### REQ-ARCH-010-B: Checksum Integrity Verification

GIVEN inspector wants to verify integrity of specific archived case (archiv-id = "EDP-2026-ABC-12345")
WHEN inspector calls `GET /api/archief/proof/{caseId}?verify=checksums`
THEN system:
- Retrieves original SipBundel checksums from ArchiefBewijs
- Displays original checksums: {bundleChecksum, documentChecksums[]}
- Provides instruction guide for e-Depot verification:
  ```
  Checksumverificatie (RHC Utrecht e-Depot):
  
  1. Contacteer RHC Utrecht: archief@rhc-utrecht.nl
  2. Verstrek archief-id: EDP-2026-ABC-12345
  3. RHC extraheert bundle uit e-Depot systeem
  4. RHC berekent checksums met: sha256sum [bundel-path]/*
  5. Vergelijk met verstrekte checksums:
     Bundel: abc123...xyz999...
     Documenten:
       - beschikking.pdf: def456...
       - beschikking.docx: ghi789...
  6. Match bewijst bit-perfect integriteit sinds overdracht
  ```
- Response status: "Checksum verification ready; contact e-Depot to confirm bit-perfect integrity"
