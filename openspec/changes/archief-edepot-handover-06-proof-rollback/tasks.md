# Tasks: archief-edepot-handover-06-proof-rollback

Chain member 6 of 8 (`kind: code`, depends_on member 05). Traces to giant Tasks 12–14 / REQ-ARCH-006, 007, 008.

## 1. ProofOfTransferRecorder

- [ ] Implement `createArchiefBewijs(caseId, archivId, receipt, eDepotName, ingestionDate)`: copy SIP checksums, store receipt, status `received`
- [ ] Implement `attachProofToCase(caseId, bewijsId)`: read-only file typed `ArchiefBewijs`, prevent modification/deletion
- [ ] Implement `verifyIntegrity(bewijsId, sipBundelId)`: compare checksums, alert DIV on mismatch
- [ ] Read/write via OpenRegister ObjectService (no bespoke SQL)

## 2. RollbackManager

- [ ] Implement `onIngestionFailure(transactionId, errorCode, errorDetail)`: transaction `failed-final`, trigger `gefaald`, preserve SIP, case unmodified, log `submission-failed-rollback`
- [ ] Implement `recommendCorrectiveAction(errorCode, caseContext)` mapping MDTO_VALIDATION_FAILED / CHECKSUM_MISMATCH / DOCUMENT_CONVERSION_FAILED / E_DEPOT_CAPACITY_EXCEEDED to instructions
- [ ] Create DIV task: title "Zaak [id] overdracht mislukt: [errorCode]", description + corrective steps, linked to SIP + case

## 3. Retry-after-correction

- [ ] Implement `POST /api/archief/triggers/{triggerId}/retry`: fetch current case state, new bundling + submission, log old + new transactions
- [ ] Declare explicit auth posture + IDOR guard (caller authorised for the case)
- [ ] Validate retry only allowed on triggers in status `gefaald`

## 4. Tests

- [ ] Test: success creates `ArchiefBewijs` attached read-only to the case; receipt + metadata intact
- [ ] Test: checksum verification detects a simulated mismatch and alerts DIV
- [ ] Test: e-Depot rejection preserves the case, marks trigger `gefaald`, notifies DIV with corrective steps
- [ ] Test: correct field then retry succeeds; audit trail shows both failed and successful transactions
