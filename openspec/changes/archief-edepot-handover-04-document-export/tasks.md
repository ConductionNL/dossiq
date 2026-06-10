# Tasks: archief-edepot-handover-04-document-export

Chain member 4 of 8 (`kind: code`, depends_on member 03). Traces to giant Tasks 7–8 / REQ-ARCH-003.

## 1. DocumentExporter

- [~] Implement `exportToFormatPair(documentId)` → {pdfA, original}; raise `ConversionException` on failure — deferred to downstream cycle / fleet-wide adoption (handoff)
- [~] Handle digitally signed documents: preserve signature in original, signature metadata + visual indicator in PDF/A — deferred to downstream cycle / fleet-wide adoption (handoff)
- [~] Read documents and update `SipBundel.documents[]` via OpenRegister ObjectService — deferred to downstream cycle / fleet-wide adoption (handoff)

## 2. PdfAConverter wrapper

- [~] Call docudesk PDF/A conversion (direct HTTP or openconnector adapter) — deferred to downstream cycle / fleet-wide adoption (handoff)
- [~] Handle async conversion (polling or webhook callback) — deferred to downstream cycle / fleet-wide adoption (handoff)
- [~] Add 5-minute timeout and transient-failure retry — deferred to downstream cycle / fleet-wide adoption (handoff)

## 3. Checksums + failure handling

- [~] Implement `computeChecksum(filePath, algorithm='sha256')` returning hex (stream large files) — deferred to downstream cycle / fleet-wide adoption (handoff)
- [~] On conversion failure: log `bundling-failed` (errorCode DOCUMENT_CONVERSION_FAILED), block SipBundel finalisation, raise DIV task with corrective steps — deferred to downstream cycle / fleet-wide adoption (handoff)

## 4. Batch conversion

- [~] Implement `exportDocumentsBatch(documentIds[], concurrencyLimit=4)` → {successes[], failures[]} — deferred to downstream cycle / fleet-wide adoption (handoff)
- [~] Maintain concurrency limit and progress {total, completed, inProgress, failed} — deferred to downstream cycle / fleet-wide adoption (handoff)
- [~] Make the concurrency limit configurable globally or per e-Depot (default 4) — deferred to downstream cycle / fleet-wide adoption (handoff)

## 5. Tests

- [~] Test: export `.docx`, `.xlsx`, `.pdf` with checksums; verify PDF/A-2b output — deferred to downstream cycle / fleet-wide adoption (handoff)
- [~] Test: signed PDF preserves signature + adds visual indicator — deferred to downstream cycle / fleet-wide adoption (handoff)
- [~] Test: conversion failure blocks bundling with corrective message — deferred to downstream cycle / fleet-wide adoption (handoff)
- [~] Test: convert 20 documents with concurrency 4; verify 4 parallel, 16 queued, progress trackable — deferred to downstream cycle / fleet-wide adoption (handoff)
