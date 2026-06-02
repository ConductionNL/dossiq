---
kind: code
depends_on:
  - archief-edepot-handover-06-proof-rollback
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

# Proposal: archief-edepot-handover-07-batch-inspection

## Summary

This is **spec 7 of 8** in the `archief-edepot-handover` chain. It adds bulk batch processing over the single-case pipeline (members 03–06) and the audit-grade inspection export for provincial inspectors, backed by the append-only `OverdrachtAuditLog`. `kind: code`; `depends_on` member 06.

## Why

DIV must archive hundreds of dossiers per retention cohort, and provincial inspectors must be able to audit compliance. This member orchestrates the per-case path concurrently with reporting, and produces the on-demand inspection ZIP plus an audit-log query API.

## What Changes

1. **ArchivalBatchProcessor** — `initiateBatch()`, `processCaseInBatch()`, concurrency control, per-case status, batch report (CSV + stats).
2. **Batch endpoints** — `POST /api/archief/batch/initiate`, `GET /api/archief/batch/{jobId}`, `GET /api/archief/batch/{jobId}/report`.
3. **InspectionExportService** — `generateInspectionExport(year)` → ZIP (CSV summary, ArchiefBewijs PDFs, statistics, checksum guide); `GET /api/archief/inspection-export`.
4. **Audit-trail query** — event types defined across the pipeline; `GET /api/archief/audit-log?zaakId=` returns the immutable event stream.

## Impact

- **Affected**: procest (batch processor, batch controller, inspection export, audit-log controller).
- **Consumes**: the single-case pipeline (members 03–06), `OverdrachtAuditLog`, `ArchiefBewijs`, `OverdrachtTrigger` (member 01).
- **Downstream**: member 08 adds the admin UI/dashboard, tests, and docs over these endpoints.

## Traceability

Covers giant tasks **15** (ArchivalBatchProcessor), **16** (batch initiation endpoint), **17** (inspection export), and **18** (audit trail); requirements REQ-ARCH-009 (A/B) and REQ-ARCH-010 (A/B). No new scope.
