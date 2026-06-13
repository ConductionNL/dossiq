---
kind: code
depends_on:
  - archief-edepot-handover-03-metadata-bundling
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

# Proposal: archief-edepot-handover-04-document-export

## Summary

This is **spec 4 of 8** in the `archief-edepot-handover` chain. It implements the document exporter: for each case document it produces a PDF/A rendering (via docudesk) plus the preserved original, computes SHA-256 checksums for both, preserves digital-signature metadata, and supports rate-limited batch conversion. `kind: code`; `depends_on` member 03 (the bundle's `documents[]` array is populated from these exports).

## Why

Long-term archival requires both a bit-perfect original and a PDF/A rendering for future readability. This member is the format-conversion half of bundle assembly. It deliberately excludes BagIt packaging and submission (member 05).

## What Changes

1. **DocumentExporter** — `exportToFormatPair(documentId)` returns {pdfA, original}, handling digitally signed documents.
2. **PdfAConverter** — wrapper around docudesk (sync/async with polling, timeout, transient-retry).
3. **ChecksumComputer** — `computeChecksum(filePath, sha256)` for both variants.
4. **Conversion-failure handling** — blocks bundling, logs the failure, raises a DIV task with corrective guidance.
5. **Batch conversion** — `exportDocumentsBatch(documentIds[], concurrencyLimit)` with progress tracking and a configurable rate-limit.

## Impact

- **Affected**: procest (exporter, PDF/A wrapper, checksum util), docudesk (PDF/A service, called via API).
- **Consumes**: case documents; writes into the `SipBundel.documents[]` shape from member 03.
- **Downstream**: member 05 packages the exported files into a BagIt SIP.

## Traceability

Covers giant tasks **7** (DocumentExporter) and **8** (batch conversion with rate-limiting); requirement REQ-ARCH-003 (A/B/C). No new scope.
