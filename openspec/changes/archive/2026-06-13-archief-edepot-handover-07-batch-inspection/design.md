# Design: archief-edepot-handover-07-batch-inspection

## Scope

Bulk batch orchestration over the single-case pipeline (members 03–06), inspection export, and the audit-log query API. Admin UI/dashboard, end-to-end tests, and documentation are member 08.

## Declarative-first (ADR-031)

Batch orchestration (concurrency, per-case state, report assembly) and ZIP/CSV/PDF export are imperative work with no declarative analogue per ADR-031 — services are correct. The records read (`OverdrachtAuditLog`, `ArchiefBewijs`) are declarative schema objects (member 01); the audit log is append-only by schema convention.

## Data access (ADR-001)

Batch job state, `OverdrachtTrigger`, `ArchiefBewijs`, and `OverdrachtAuditLog` reads/writes go through the OpenRegister ObjectService. No bespoke SQL.

## Service layout

### ArchivalBatchProcessor
- `initiateBatch(caseIds[], rateLimit=4, eDepotId)` — create a batch job, queue cases, set concurrency; returns jobId.
- `processCaseInBatch(caseId)` — bundle → submit → proof/rollback (reusing members 03–06); never blocks the batch on one case failure.
- State machine queued → processing → completed/partially-failed; tracks totalCases/completed/inProgress/pending/succeeded/failed.
- `generateBatchReport(jobId)` — CSV per case + text stats; ZIP.

### Batch endpoints
- `POST /api/archief/batch/initiate` (validate cases exist + `gereed-voor-overdracht`), `GET /api/archief/batch/{jobId}`, `GET /api/archief/batch/{jobId}/report`.

### InspectionExportService
- `generateInspectionExport(year, filters)` — query `geslaagd` triggers for the year, collect `ArchiefBewijs` PDFs, build CSV summary + statistics PDF + checksum-verification guide; ZIP. `GET /api/archief/inspection-export?year=`.

### Audit-trail query
- Event-type vocabulary used across the pipeline; `GET /api/archief/audit-log?zaakId=` returns reverse-chronological immutable events.

## Security (ADR-005)

Every endpoint here is user-facing and MUST declare an explicit auth posture. Batch initiation and inspection export are DIV/admin operations and MUST be gated accordingly; the audit-log and report queries MUST enforce that the caller is authorised for the cases referenced (no cross-tenant leakage). Batch input is validated (cases exist and are in the correct status) before any submission. The audit log is read-only via these endpoints — append-only is preserved by the schema.

## Traceability

Giant Task 15 (batch processor), Task 16 (batch initiation), Task 17 (inspection export), Task 18 (audit trail); REQ-ARCH-009-A/B, REQ-ARCH-010-A/B.
