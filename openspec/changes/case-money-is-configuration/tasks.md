# Tasks: case-money-is-configuration

<!-- HYDRA CAP: max 20 unindented `- [ ]` lines. This file uses 6. -->

- [ ] 1. Case detail places `shillinq-financial-documents-panel`; the linked card lists it (REQ-DQM-001). Verify: `npm run check:manifest`, vitest on the placement.
- [ ] 2. `PayoutRequester` sends the case as subject (REQ-DQM-002). Verify: PayoutRequesterTest red first.
- [ ] 3. `AccruingAmountController` and `Money\AmountObjectionService`; new routes, old URLs as aliases (REQ-DQM-003). Verify: RenamedRouteAliasesTest, DwangsomControllerAuthzTest moved, route gates.
- [ ] 4. Config keys renamed with repair and fallback read (REQ-DQM-003). Verify: MigrateAppConfigKeysTest.
- [ ] 5. Schema titles and descriptions without the procedure name; digests regenerated (REQ-DQM-003). Verify: schema digest test.
- [ ] 6. Playwright: the penalty payout shows on its case (REQ-DQM-002). Verify: passes locally.
