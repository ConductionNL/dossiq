# Tasks: archief-edepot-handover-07-batch-inspection

Chain member 7 of 8 (`kind: code`, depends_on member 06). Traces to giant Tasks 15–18 / REQ-ARCH-009, 010.

## 1. ArchivalBatchProcessor

- [ ] Implement `initiateBatch(caseIds[], rateLimit=4, eDepotId)` creating a batch job and queuing cases
- [ ] Implement the batch state machine queued → processing → completed/partially-failed with counters
- [ ] Implement `processCaseInBatch(caseId)` reusing bundle → submit → proof/rollback; do not block the batch on one failure
- [ ] Implement concurrency control (spawn up to rateLimit, refill as tasks complete)
- [ ] Read/write batch + trigger objects via OpenRegister ObjectService (no bespoke SQL)

## 2. Batch endpoints

- [ ] Implement `POST /api/archief/batch/initiate` (validate cases exist + `gereed-voor-overdracht`); declare auth posture (DIV/admin)
- [ ] Implement `GET /api/archief/batch/{jobId}` returning progress + failed-cases list
- [ ] Implement `GET /api/archief/batch/{jobId}/report` returning a ZIP (summary.csv, failed-cases.txt, batch-stats.txt)

## 3. Inspection export

- [ ] Implement `generateInspectionExport(year, filters)`: query `geslaagd` triggers, collect ArchiefBewijs PDFs, build CSV + statistics PDF + checksum guide; ZIP
- [ ] Implement `GET /api/archief/inspection-export?year=` with an authorised-inspector auth posture

## 4. Audit trail

- [ ] Define the archival event-type vocabulary (trigger-detected, bundling-*, submission-*, rollback-executed, proof-captured/verified, batch-initiated/completed)
- [ ] Ensure each pipeline milestone calls `logEvent(...)` into the append-only `OverdrachtAuditLog`
- [ ] Implement `GET /api/archief/audit-log?zaakId=` returning reverse-chronological immutable events

## 5. Tests

- [ ] Test: initiate a batch of 250 with rateLimit 4; verify 4 parallel, progress trackable
- [ ] Test: batch with 245 success + 5 failed produces a correct report
- [ ] Test: generate the 2026 inspection export; verify CSV, PDFs, stats, guide in the ZIP
- [ ] Test: full workflow emits all expected immutable audit events queryable per case
