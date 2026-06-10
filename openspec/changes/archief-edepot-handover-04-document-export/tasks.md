# Tasks: archief-edepot-handover-04-document-export

Chain member 4 of 8 (`kind: code`, depends_on member 03). Traces to giant Tasks 7–8 / REQ-ARCH-003.

## 1. DocumentExporter

- [ ] Implement `exportToFormatPair(documentId)` → {pdfA, original}; raise `ConversionException` on failure
- [ ] Handle digitally signed documents: preserve signature in original, signature metadata + visual indicator in PDF/A
- [ ] Read documents and update `SipBundel.documents[]` via OpenRegister ObjectService

## 2. PdfAConverter wrapper

- [ ] Call docudesk PDF/A conversion (direct HTTP or openconnector adapter)
- [ ] Handle async conversion (polling or webhook callback)
- [ ] Add 5-minute timeout and transient-failure retry

## 3. Checksums + failure handling

- [ ] Implement `computeChecksum(filePath, algorithm='sha256')` returning hex (stream large files)
- [ ] On conversion failure: log `bundling-failed` (errorCode DOCUMENT_CONVERSION_FAILED), block SipBundel finalisation, raise DIV task with corrective steps

## 4. Batch conversion

- [ ] Implement `exportDocumentsBatch(documentIds[], concurrencyLimit=4)` → {successes[], failures[]}
- [ ] Maintain concurrency limit and progress {total, completed, inProgress, failed}
- [ ] Make the concurrency limit configurable globally or per e-Depot (default 4)

## 5. Tests

- [ ] Test: export `.docx`, `.xlsx`, `.pdf` with checksums; verify PDF/A-2b output
- [ ] Test: signed PDF preserves signature + adds visual indicator
- [ ] Test: conversion failure blocks bundling with corrective message
- [ ] Test: convert 20 documents with concurrency 4; verify 4 parallel, 16 queued, progress trackable
