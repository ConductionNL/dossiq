# Tasks: archief-edepot-handover-06-proof-rollback

Chain member 6 of 8 (`kind: code`, depends_on member 05). Traces to giant Tasks 12–14 / REQ-ARCH-006, 007, 008.

## 1. ProofOfTransferRecorder

- [~] Implement `createArchiefBewijs(caseId, archivId, receipt, eDepotName, ingestionDate)` — DEFERRED with member 05 (no submitter yet); the `archiefBewijs` schema and ObjectService path exist; recorder will call `objectService->saveObject('procest', 'archiefBewijs', $row)`
- [~] Implement `attachProofToCase(caseId, bewijsId)` — DEFERRED with member 05; will use Nextcloud Files API to create a read-only `ArchiefBewijs.pdf` typed file in the case folder
- [~] Implement `verifyIntegrity(bewijsId, sipBundelId)` — DEFERRED with member 05; checksum comparison logic reuses `BagItBundlerService::computeChecksum`
- [x] Read/write via OpenRegister ObjectService (no bespoke SQL) — `archiefBewijs` schema is registered; ObjectService is the only access path

## 2. RollbackManager

- [~] Implement `onIngestionFailure(transactionId, errorCode, errorDetail)` — DEFERRED with member 05; rollback semantics are append-only (no SIP destruction), so the implementation is mostly state transitions on `OverdrachtTransactie` + `OverdrachtTrigger`
- [~] Implement `recommendCorrectiveAction(errorCode, caseContext)` — DEFERRED with member 05; a static map of error-code → advice strings lives in `lib/Settings/templates/archief/corrective-actions.json` (file to be added with the rollback service)
- [~] Create DIV task with corrective steps, linked to SIP + case — DEFERRED with TASK-06-02; reuses the `ArchivalTriggerService::notifyDiv` plumbing

## 3. Retry-after-correction

- [~] Implement `POST /api/archief/triggers/{triggerId}/retry` — DEFERRED with member 05; controller skeleton is reserved in `lib/Controller/ArchiefController.php` (only `listRules`/`createRule`/`dashboardStats`/`auditLog` shipped so far)
- [~] Declare explicit auth posture + IDOR guard — will follow the controller's existing `requireAuthenticated()` + per-trigger `requireRoleForCase()` pattern when added
- [~] Validate retry only allowed on triggers in status `gefaald` — DEFERRED with TASK-06-03

## 4. Tests

- [~] All four proof/rollback tests — DEFERRED with members 05/06; the `archiefBewijs` schema contract IS covered by the schema-validation pass in member 01
