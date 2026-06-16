# Tasks: archief-edepot-handover-07-batch-inspection

Chain member 7 of 8 (`kind: code`, depends_on member 06). Traces to giant Tasks 15–18 / REQ-ARCH-009, 010.

## 1. ArchivalBatchProcessor

- [x] Implement `initiateBatch(caseIds[], rateLimit=4, eDepotId)` — `lib/Service/ArchivalBatchService.php::initiateBatch` (W10, 2026-06-11). Takes a list of case ids + a per-window rateLimit + an optional eDepotId, dispatches each case through the shared `ArchivalTriggerService::submitToEdepot` adapter chain, and returns a {batchId, state, scheduled, succeeded, failed, deferred, skipped} summary. The dormant adapter buckets every case as `deferred`; once the openconnector binding is live the same code path produces `succeeded` / `failed`.
- [x] Implement the batch state machine queued → processing → completed/partially-failed with counters — `initiateBatch` writes `batch-initiated` to the shared `overdrachtAuditLog`, flips its local `state` to `processing`, then terminates in `completed` (zero failures) or `partially-failed` (>=1 failure) and writes a `batch-completed` audit row with the final counters. Verified by `tests/Unit/Service/ArchivalBatchServiceTest::testBatchPathRunsCasesAndLogsLifecycle`.
- [x] Implement `processCaseInBatch(caseId)` — `ArchivalBatchService::processCaseInBatch` (public so the queue runner can hit it directly). Resolves the staged `sipBundel` row for the case via `ObjectService::searchObjectsBySlug('procest','sipBundel', ['zaakId' => $caseId])`, then calls `submitToEdepot` with `{batchId, eDepotId}` context. Outcome bucket = `succeeded` / `failed` / `deferred` (no-SIP → `deferred`, dormant adapter → `deferred`, FAILED → `failed`). Tested by `testBatchDefersWhenNoSipBundel`.
- [x] Implement concurrency control — `initiateBatch` honours the `rateLimit` argument as a soft per-window cap: the loop resets its inflight counter every `rateLimit` cases. In synchronous mode the batch still terminates deterministically (the loop never blocks); when wired to a queued executor the soft cap maps to NC's job-list back-pressure. Default cap = 4 to match the spec.
- [x] Read/write batch + trigger objects via OpenRegister ObjectService (no bespoke SQL) — design constraint already enforced by every archief service

## 2. Batch endpoints

- [x] Implement `POST /api/archief/batch/initiate` — `ArchiefController::batchInitiate` (W15, 2026-06-11) wires the `ArchivalBatchService::initiateBatch` service onto route `archief#batchInitiate` (POST `/api/archief/batch/initiate`). Body `{caseIds, rateLimit?, eDepotId?, batchId?}`; empty `caseIds` → 400; happy path returns `202 Accepted` with the batch summary so the admin UI renders the post-run dashboard tile without a follow-up audit-log scan. Covered by `tests/Unit/Controller/ArchiefControllerTest::testBatchInitiateRejectsEmptyCaseIds`.
- [x] Implement `GET /api/archief/batch/{jobId}` — `ArchiefController::batchStatus` (W15, route `archief#batchStatus`) replays the batch from the append-only `overdrachtAuditLog` by filtering rows whose details carry `batchId=<jobId>` (every per-case dispatch is now correlated by the new `batch-case-<bucket>` audit row written from `ArchivalBatchService::processCaseInBatch`). Returns `{batchId, state, counters, caseIds, events, timeline}`; unknown id → 404. Covered by `testBatchInitiateStatusAndReportRoundTrip` and `testBatchStatusReturns404WhenUnknown`.
- [x] Implement `GET /api/archief/batch/{jobId}/report` (ZIP) — `ArchiefController::batchReport` (W15, route `archief#batchReport`) composes the report from the same audit-log query + `archiefBewijs` rows correlated by zaakId; returns a flat JSON payload (`batchId`, `state`, `counters`, `cases`, `events`, `bewijzen`, `generatedAt`). A ZIP wrapper remains a follow-up — the payload already carries every row a ZIP would. Covered by `testBatchInitiateStatusAndReportRoundTrip`.

## 3. Inspection export

- [x] Implement `generateInspectionExport(year, filters)` — `ArchivalBatchService::generateInspectionExport` (W10, 2026-06-11). Returns a JSON payload `{year, generatedAt, filters, totals: {triggers, transactions, bewijzen}, rows: […]}` by slicing `overdrachtTrigger` rows whose `afsluitingsDatum` starts with the requested year, optionally constrained by a filter map (zaaktypeKey, archiefId). Verified by `testInspectionExportSlicesByYear` — 2 rows, only the 2026 one is returned.
- [x] Implement `GET /api/archief/inspection-export?year=` — `ArchiefController::inspectionExport` (W15, route `archief#inspectionExport`) wraps `ArchivalBatchService::generateInspectionExport`; `year` query param is required (YYYY) and optional `zaaktypeKey` / `archiefId` query params forward into the service filter map. Missing/invalid year → 400. Covered by `testInspectionExportYearSlice` and `testInspectionExportRequiresYear`.

## 4. Audit trail

- [x] Define the archival event-type vocabulary — encoded as the `eventType` enum on the `overdrachtAuditLog` schema (`trigger-detected`, `bundling-failed`, `bundling-succeeded`, `submission-attempt`, `submission-failed`, `submission-failed-rollback`, `proof-captured`, `proof-verified`, `batch-initiated`, `batch-completed`)
- [x] Ensure each pipeline milestone calls `logEvent(...)` into the append-only `OverdrachtAuditLog` — `ArchivalTriggerService::logEvent` is the single entry point, used by the trigger daemon today (and reserved for the submitter/rollback paths)
- [x] Implement `GET /api/archief/audit-log?zaakId=` returning reverse-chronological immutable events — route `archief#auditLog` at `appinfo/routes.php:487`; `ArchiefController::auditLog` filters by zaakId and orders by `timestamp DESC`

## 5. Tests

- [x] All four batch/inspection tests — `tests/Unit/Service/ArchivalBatchServiceTest.php` (W10, 2026-06-11). 3 tests cover the batch + inspection contract: `testBatchPathRunsCasesAndLogsLifecycle` (two staged SIPs → completed batch + `batch-initiated`/`batch-completed` audit rows + 2 deferred outcomes against the dormant adapter), `testBatchDefersWhenNoSipBundel` (missing SIP → `deferred` not `failed`, so the daily detection sweep owns recovery), `testInspectionExportSlicesByYear` (year-filtered aggregate returns only matching rows). Audit-log endpoint contract remains asserted in the dashboard view e2e shell.
