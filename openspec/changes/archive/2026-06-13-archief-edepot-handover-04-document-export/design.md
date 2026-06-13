# Design: archief-edepot-handover-04-document-export

## Scope

Document format conversion (PDF/A + original) and checksums only. BagIt packaging, manifest, and submission are member 05; proof/rollback member 06.

## Declarative-first (ADR-031)

Format conversion and checksum computation are inherently imperative external-service glue (docudesk PDF/A) with no declarative analogue per ADR-031 — a service is correct. The exported file references are stored back into the declarative `SipBundel.documents[]` array (member 01 schema).

## Data access (ADR-001)

Document loads and `SipBundel` updates go through the OpenRegister ObjectService; docudesk is reached via its API (directly or via an openconnector adapter). No bespoke SQL.

## Service layout

### DocumentExporter
- `exportToFormatPair(documentId)` → {pdfA: {filePath, mimeType, fileSize}, original: {...}}; preserves signature metadata for signed docs; raises `ConversionException` on failure.
- `exportDocumentsBatch(documentIds[], concurrencyLimit=4)` → {successes[], failures[]} with progress {total, completed, inProgress, failed}.

### PdfAConverter
- Calls docudesk (direct HTTP or openconnector adapter); handles async (polling/webhook), 5-minute timeout, transient-failure retry.

### ChecksumComputer
- `computeChecksum(filePath, algorithm='sha256')` → hex; streams large files.

## Conversion-failure handling (no partial bundle)

On conversion failure: log an `OverdrachtAuditLog` `bundling-failed` event with `errorCode: DOCUMENT_CONVERSION_FAILED`, block `SipBundel` finalisation, and raise a DIV task naming the file and the corrective action (replace/re-scan, then retry).

## Rate limiting

Concurrency defaults to 4 to avoid overloading docudesk / the e-Depot; configurable globally or per e-Depot. The batch path spawns a new conversion only as a prior one finishes and exposes progress for monitoring.

## Security (ADR-005)

No new user-facing endpoint; the exporter is invoked by the bundling pipeline. Files written to the bundle are checksummed so any in-transit corruption is detectable downstream (member 05/06). Signed-document handling preserves signature integrity rather than re-signing.

## Traceability

Giant Task 7 (DocumentExporter) + Task 8 (batch conversion); REQ-ARCH-003-A/B/C.
