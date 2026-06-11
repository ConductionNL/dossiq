# Tasks: archief-edepot-handover-07-batch-inspection

Chain member 7 of 8 (`kind: code`, depends_on member 06). Traces to giant Tasks 15–18 / REQ-ARCH-009, 010.

## 1. ArchivalBatchProcessor

- [~] Implement `initiateBatch(caseIds[], rateLimit=4, eDepotId)` — DEFERRED with member 05/06; batch state machine cannot be exercised without a submitter
- [~] Implement the batch state machine queued → processing → completed/partially-failed with counters — DEFERRED with TASK-07-01
- [~] Implement `processCaseInBatch(caseId)` — DEFERRED with TASK-07-01
- [~] Implement concurrency control — DEFERRED with TASK-07-01
- [x] Read/write batch + trigger objects via OpenRegister ObjectService (no bespoke SQL) — design constraint already enforced by every archief service

## 2. Batch endpoints

- [~] Implement `POST /api/archief/batch/initiate` — DEFERRED with TASK-07-01; route slot is reserved
- [~] Implement `GET /api/archief/batch/{jobId}` — DEFERRED with TASK-07-01
- [~] Implement `GET /api/archief/batch/{jobId}/report` (ZIP) — DEFERRED with TASK-07-01

## 3. Inspection export

- [~] Implement `generateInspectionExport(year, filters)` — DEFERRED with member 06 (depends on `ArchiefBewijs` artefacts which require a live submitter); the data shape is fully defined in the `archiefBewijs` schema
- [~] Implement `GET /api/archief/inspection-export?year=` — DEFERRED with TASK-07-09

## 4. Audit trail

- [x] Define the archival event-type vocabulary — encoded as the `eventType` enum on the `overdrachtAuditLog` schema (`trigger-detected`, `bundling-failed`, `bundling-succeeded`, `submission-attempt`, `submission-failed`, `submission-failed-rollback`, `proof-captured`, `proof-verified`, `batch-initiated`, `batch-completed`)
- [x] Ensure each pipeline milestone calls `logEvent(...)` into the append-only `OverdrachtAuditLog` — `ArchivalTriggerService::logEvent` is the single entry point, used by the trigger daemon today (and reserved for the submitter/rollback paths)
- [x] Implement `GET /api/archief/audit-log?zaakId=` returning reverse-chronological immutable events — route `archief#auditLog` at `appinfo/routes.php:487`; `ArchiefController::auditLog` filters by zaakId and orders by `timestamp DESC`

## 5. Tests

- [~] All four batch/inspection tests — DEFERRED with TASK-07-01 (no batch processor to test); audit-log endpoint contract IS asserted in the dashboard view e2e shell
