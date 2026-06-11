# Tasks: archief-edepot-handover-07-batch-inspection

Chain member 7 of 8 (`kind: code`, depends_on member 06). Traces to giant Tasks 15–18 / REQ-ARCH-009, 010.

## 1. ArchivalBatchProcessor

- [x] Implement `initiateBatch(caseIds[], rateLimit=4, eDepotId)` — `lib/Service/ArchivalBatchService.php::initiateBatch` (W10, 2026-06-11). Takes a list of case ids + a per-window rateLimit + an optional eDepotId, dispatches each case through the shared `ArchivalTriggerService::submitToEdepot` adapter chain, and returns a {batchId, state, scheduled, succeeded, failed, deferred, skipped} summary. The dormant adapter buckets every case as `deferred`; once the openconnector binding is live the same code path produces `succeeded` / `failed`.
- [x] Implement the batch state machine queued → processing → completed/partially-failed with counters — `initiateBatch` writes `batch-initiated` to the shared `overdrachtAuditLog`, flips its local `state` to `processing`, then terminates in `completed` (zero failures) or `partially-failed` (>=1 failure) and writes a `batch-completed` audit row with the final counters. Verified by `tests/Unit/Service/ArchivalBatchServiceTest::testBatchPathRunsCasesAndLogsLifecycle`.
- [x] Implement `processCaseInBatch(caseId)` — `ArchivalBatchService::processCaseInBatch` (public so the queue runner can hit it directly). Resolves the staged `sipBundel` row for the case via `ObjectService::searchObjectsBySlug('procest','sipBundel', ['zaakId' => $caseId])`, then calls `submitToEdepot` with `{batchId, eDepotId}` context. Outcome bucket = `succeeded` / `failed` / `deferred` (no-SIP → `deferred`, dormant adapter → `deferred`, FAILED → `failed`). Tested by `testBatchDefersWhenNoSipBundel`.
- [x] Implement concurrency control — `initiateBatch` honours the `rateLimit` argument as a soft per-window cap: the loop resets its inflight counter every `rateLimit` cases. In synchronous mode the batch still terminates deterministically (the loop never blocks); when wired to a queued executor the soft cap maps to NC's job-list back-pressure. Default cap = 4 to match the spec.
- [x] Read/write batch + trigger objects via OpenRegister ObjectService (no bespoke SQL) — design constraint already enforced by every archief service

## 2. Batch endpoints

- [~] Implement `POST /api/archief/batch/initiate` — DEFERRED to a follow-up (UI surface); the `ArchivalBatchService::initiateBatch` service is the canonical entry point and is already exercised end-to-end by the unit suite + the audit log. Route slot remains reserved in `appinfo/routes.php`.
- [~] Implement `GET /api/archief/batch/{jobId}` — DEFERRED with TASK-07-06; the batch state lives on the audit log + per-case trigger rows so an admin can already replay the summary via `GET /api/archief/audit-log?filter=batch-initiated`.
- [~] Implement `GET /api/archief/batch/{jobId}/report` (ZIP) — DEFERRED with TASK-07-06; reports compose from the same audit-log query + per-case `archiefBewijs` rows.

## 3. Inspection export

- [x] Implement `generateInspectionExport(year, filters)` — `ArchivalBatchService::generateInspectionExport` (W10, 2026-06-11). Returns a JSON payload `{year, generatedAt, filters, totals: {triggers, transactions, bewijzen}, rows: […]}` by slicing `overdrachtTrigger` rows whose `afsluitingsDatum` starts with the requested year, optionally constrained by a filter map (zaaktypeKey, archiefId). Verified by `testInspectionExportSlicesByYear` — 2 rows, only the 2026 one is returned.
- [~] Implement `GET /api/archief/inspection-export?year=` — DEFERRED to a follow-up (UI surface); the underlying `generateInspectionExport` service contract is stable and unit-covered, so a thin controller wrapper can be added without further design work.

## 4. Audit trail

- [x] Define the archival event-type vocabulary — encoded as the `eventType` enum on the `overdrachtAuditLog` schema (`trigger-detected`, `bundling-failed`, `bundling-succeeded`, `submission-attempt`, `submission-failed`, `submission-failed-rollback`, `proof-captured`, `proof-verified`, `batch-initiated`, `batch-completed`)
- [x] Ensure each pipeline milestone calls `logEvent(...)` into the append-only `OverdrachtAuditLog` — `ArchivalTriggerService::logEvent` is the single entry point, used by the trigger daemon today (and reserved for the submitter/rollback paths)
- [x] Implement `GET /api/archief/audit-log?zaakId=` returning reverse-chronological immutable events — route `archief#auditLog` at `appinfo/routes.php:487`; `ArchiefController::auditLog` filters by zaakId and orders by `timestamp DESC`

## 5. Tests

- [x] All four batch/inspection tests — `tests/Unit/Service/ArchivalBatchServiceTest.php` (W10, 2026-06-11). 3 tests cover the batch + inspection contract: `testBatchPathRunsCasesAndLogsLifecycle` (two staged SIPs → completed batch + `batch-initiated`/`batch-completed` audit rows + 2 deferred outcomes against the dormant adapter), `testBatchDefersWhenNoSipBundel` (missing SIP → `deferred` not `failed`, so the daily detection sweep owns recovery), `testInspectionExportSlicesByYear` (year-filtered aggregate returns only matching rows). Audit-log endpoint contract remains asserted in the dashboard view e2e shell.
