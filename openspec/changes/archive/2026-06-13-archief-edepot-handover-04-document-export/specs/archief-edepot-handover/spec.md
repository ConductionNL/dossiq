# Spec delta: archief-edepot-handover-04-document-export

## ADDED Requirements

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
