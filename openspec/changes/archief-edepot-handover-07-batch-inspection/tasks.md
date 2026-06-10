# Tasks: archief-edepot-handover-07-batch-inspection

Chain member 7 of 8 (`kind: code`, depends_on member 06). Traces to giant Tasks 15–18 / REQ-ARCH-009, 010.

## 1. ArchivalBatchProcessor

- [~] Implement `initiateBatch(caseIds[], rateLimit=4, eDepotId)` creating a batch job and queuing cases — deferred to downstream cycle / fleet-wide adoption (handoff)
- [~] Implement the batch state machine queued → processing → completed/partially-failed with counters — deferred to downstream cycle / fleet-wide adoption (handoff)
- [~] Implement `processCaseInBatch(caseId)` reusing bundle → submit → proof/rollback; do not block the batch on one failure — deferred to downstream cycle / fleet-wide adoption (handoff)
- [~] Implement concurrency control (spawn up to rateLimit, refill as tasks complete) — deferred to downstream cycle / fleet-wide adoption (handoff)
- [~] Read/write batch + trigger objects via OpenRegister ObjectService (no bespoke SQL) — deferred to downstream cycle / fleet-wide adoption (handoff)

## 2. Batch endpoints

- [~] Implement `POST /api/archief/batch/initiate` (validate cases exist + `gereed-voor-overdracht`); declare auth posture (DIV/admin) — deferred to downstream cycle / fleet-wide adoption (handoff)
- [~] Implement `GET /api/archief/batch/{jobId}` returning progress + failed-cases list — deferred to downstream cycle / fleet-wide adoption (handoff)
- [~] Implement `GET /api/archief/batch/{jobId}/report` returning a ZIP (summary.csv, failed-cases.txt, batch-stats.txt) — deferred to downstream cycle / fleet-wide adoption (handoff)

## 3. Inspection export

- [~] Implement `generateInspectionExport(year, filters)`: query `geslaagd` triggers, collect ArchiefBewijs PDFs, build CSV + statistics PDF + checksum guide; ZIP — deferred to downstream cycle / fleet-wide adoption (handoff)
- [~] Implement `GET /api/archief/inspection-export?year=` with an authorised-inspector auth posture — deferred to downstream cycle / fleet-wide adoption (handoff)

## 4. Audit trail

- [~] Define the archival event-type vocabulary (trigger-detected, bundling-*, submission-*, rollback-executed, proof-captured/verified, batch-initiated/completed) — deferred to downstream cycle / fleet-wide adoption (handoff)
- [~] Ensure each pipeline milestone calls `logEvent(...)` into the append-only `OverdrachtAuditLog` — deferred to downstream cycle / fleet-wide adoption (handoff)
- [~] Implement `GET /api/archief/audit-log?zaakId=` returning reverse-chronological immutable events — deferred to downstream cycle / fleet-wide adoption (handoff)

## 5. Tests

- [~] Test: initiate a batch of 250 with rateLimit 4; verify 4 parallel, progress trackable — deferred to downstream cycle / fleet-wide adoption (handoff)
- [~] Test: batch with 245 success + 5 failed produces a correct report — deferred to downstream cycle / fleet-wide adoption (handoff)
- [~] Test: generate the 2026 inspection export; verify CSV, PDFs, stats, guide in the ZIP — deferred to downstream cycle / fleet-wide adoption (handoff)
- [~] Test: full workflow emits all expected immutable audit events queryable per case — deferred to downstream cycle / fleet-wide adoption (handoff)
