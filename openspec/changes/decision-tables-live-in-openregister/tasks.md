# Tasks: decision-tables-live-in-openregister

- [x] 1. `Dmn\StoredDecisionTables` (REQ-DTO-001). Verify: StoredDecisionTablesTest (rows validated against OpenRegister's schema, with a control that fails).
- [x] 2. Repair `MoveDecisionTablesToOpenRegister` (REQ-DTO-002). Verify: MoveDecisionTablesToOpenRegisterTest.
- [x] 3. `RetiredNodeTranslator` writes `tableRef` (REQ-DTO-003). Verify: RetiredNodeTranslatorTest.
- [x] 4. LHS lookups through the store (REQ-DTO-004). Verify: LhsDecisionTableLookupTest, LhsMatrixDecisionTableMigratorTest, LhsLookupServiceTest.
- [x] 5. Retire DecisionTableService, DecisionTableController and its routes, DecisionTablesTab.vue, decisionTableApi.js, decisionTableHelpers.js.
- [ ] 6. LHS package seeds `dossiq.lhs-intervention` (lane R1), then drop `LhsLookupService`'s fallbacks.
