# Tasks: case-money-is-configuration

<!-- HYDRA CAP: max 20 unindented `- [ ]` lines. This file uses 8. -->

- [ ] 1. Case detail places `shillinq-financial-documents-panel`; the linked card lists it (REQ-DQM-001). Verify: `npm run check:manifest`, vitest on the placement.
- [x] 2. `PayoutRequester` sends the case in `linkedObjects` (REQ-DQM-002). Verify: PayoutRequesterTest red first.
- [x] 3. `AccruingAmountController` and `Money\AmountObjectionService`; new routes, old URLs as aliases (REQ-DQM-003). Verify: RenamedRouteAliasesTest, DwangsomControllerAuthzTest moved, route gates.
- [x] 4. Config keys renamed, carried by `CarryRenamedSettingKeys`, legacy key read while the new one is missing (REQ-DQM-003). Verify: CarryRenamedSettingKeysTest, SettingsServiceTest, PayoutCallbackControllerTest, MoneyCodeNamesNoProcedureTest.
- [ ] 5. Schema titles and descriptions without the procedure name; digests regenerated (REQ-DQM-003). Verify: schema digest test.
- [ ] 6. Playwright: the penalty payout shows on its case (REQ-DQM-002). Verify: passes locally.
- [ ] 7. Payouts read from shillinq; repair migrates `dwangsomUitbetaling` rows; the audit reads shillinq (REQ-DQM-004). Part 1 done: PayoutReader, MigratePayoutRowsToFinance, history on the raise. Verify: PHPUnit on the repair and the audit rows, red first.
- [ ] 8. `forfeitRaisesInvoice` on the case type; a forfeited act raises the invoice (REQ-DQM-005). Verify: PHPUnit red first; Playwright.
