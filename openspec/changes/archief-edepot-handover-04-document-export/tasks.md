# Tasks: archief-edepot-handover-04-document-export

Chain member 4 of 8 (`kind: code`, depends_on member 03). Traces to giant Tasks 7–8 / REQ-ARCH-003.

## 1. DocumentExporter

- [~] Implement `exportToFormatPair(documentId)` → {pdfA, original} — DEFERRED: requires docudesk integration; ADR-022 routes PDF/A conversion through openconnector + docudesk; the BagIt bundler `BagItBundlerService::buildBagIt` currently includes the original document binaries and trusts upstream conversion. Tracked alongside `migrate-pdok-to-openconnector` for the connector layer. **W20 cross-app status (2026-06-12):** docudesk ships PDF/A-3b conversion in `docudesk/lib/Service/PdfService.php` (`pdfa` option, line 248; embedded fonts; archival metadata at line 235) — the rendering backend exists; the missing piece is an explicit cross-app entry point (HTTP endpoint or OCP-bus adapter) and the openconnector adapter shim.
- [~] Handle digitally signed documents — DEFERRED with TASK-04-01; signature metadata IS preserved on `SipBundel.documents[].documentSignature` per spec-03
- [x] Read documents and update `SipBundel.documents[]` via OpenRegister ObjectService — `BagItBundlerService` and `MetadataBundlerService` both use ObjectService only

## 2. PdfAConverter wrapper

- [~] Call docudesk PDF/A conversion (direct HTTP or openconnector adapter) — DEFERRED with TASK-04-01; the adapter pattern is sketched in `lib/Service/Beschikking/SigningAdapterInterface.php` and will reuse the same DI shape. W20 cross-app status: docudesk `PdfService::generate(..., ['pdfa' => true])` is the backend call shape the adapter would wrap.
- [~] Handle async conversion (polling or webhook callback) — DEFERRED with TASK-04-01
- [~] Add 5-minute timeout and transient-failure retry — DEFERRED with TASK-04-01

## 3. Checksums + failure handling

- [x] Implement `computeChecksum(filePath, algorithm='sha256')` returning hex — `lib/Service/BagItBundlerService.php::computeChecksum` line 129 uses `hash('sha256', ...)` and is stream-ready (accepts string content; large files iterated by the BagIt builder)
- [~] On conversion failure: log `bundling-failed`, block SipBundel finalisation, raise DIV task — DEFERRED with TASK-04-01; the error-handling skeleton is in `ArchivalTriggerService::logEvent` and `MetadataBundlerService::buildBundle` throws on missing artefacts

## 4. Batch conversion

- [~] Implement `exportDocumentsBatch(documentIds[], concurrencyLimit=4)` — DEFERRED with TASK-04-01; settings key `procest.archief.export_concurrency` is reserved (default 4)
- [~] Maintain concurrency limit and progress tracking — DEFERRED
- [~] Configurable concurrency limit globally or per e-Depot — DEFERRED

## 5. Tests

- [~] All four export tests — DEFERRED with TASK-04-01 (no converter implementation to test); checksum coverage IS available behaviourally because `BagItBundlerService::computeChecksum` is called by every BagIt build
