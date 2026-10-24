# Tasks: case-money-is-configuration

<!-- HYDRA CAP: max 20 unindented `- [ ]` lines. This file uses 8. -->

- [x] 1. Case detail places `shillinq-financial-documents-panel`; the linked card lists it (REQ-DQM-001). Verify: `npm run check:manifest`, vitest on the placement.
- [x] 2. `PayoutRequester` sends the case in `linkedObjects` (REQ-DQM-002). Verify: PayoutRequesterTest red first.
- [x] 3. `AccruingAmountController` and `Money\AmountObjectionService`; new routes, old URLs as aliases (REQ-DQM-003). Verify: RenamedRouteAliasesTest, DwangsomControllerAuthzTest moved, route gates.
- [x] 4. Config keys renamed, carried by `CarryRenamedSettingKeys`, legacy key read while the new one is missing (REQ-DQM-003). Verify: CarryRenamedSettingKeysTest, SettingsServiceTest, PayoutCallbackControllerTest, MoneyCodeNamesNoProcedureTest.
- [ ] 5. Schema titles and descriptions without the procedure name; digests regenerated (REQ-DQM-003). Verify: schema digest test.
- [ ] 6. Playwright: the penalty payout shows on its case (REQ-DQM-002). Verify: passes locally.
- [x] 7. Payouts read from shillinq; repair migrates `dwangsomUitbetaling` rows; the audit reads shillinq; no row is written for a payout finance holds; the callback and the objection reach a payout only finance holds (REQ-DQM-004). Verify: MigratePayoutRowsToFinanceTest, PayoutRequesterTest, PenaltyPaymentRowsTest, DeadlineReportingServiceTest, AmountObjectionServiceTest, PayoutCallbackControllerTest.
- [x] 8. `forfeitRaisesInvoice`, `forfeitPayerRoleType`, `forfeitAdministration`, `forfeitRequestType` on the case type (1.40.0); ForfeitInvoiceListener raises once through shillinq's leaf `createAsApp` with an invoice (REQ-DQM-005). Verify: ForfeitInvoiceRaiserTest, ForfeitInvoiceListenerTest.
