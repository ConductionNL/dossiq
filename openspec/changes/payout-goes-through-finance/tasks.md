# Tasks: payout-goes-through-finance

- [x] 1. `Money\PayoutRequester` raises and withdraws payouts (REQ-PAY-001). Verify: PayoutRequesterTest.
- [x] 2. `Money\PayoutService` (was DwangsomUitbetalingService) raises before writing and stores `payableId`; `DwangsomController::payout` route (REQ-PAY-001). Verify: PayoutServiceTest, DwangsomControllerAuthzTest; the stored row validates against the real schema.
- [x] 3. Objection withdraws and raises again (REQ-PAY-002). Verify: DwangsomBezwaarServiceTest.
- [x] 4. `Money\SignedPayoutCallback` and `PayoutCallbackController` (REQ-PAY-003). Verify: PayoutCallbackControllerTest.
- [x] 5. `Money\AccrualNoticeService` and `AccrualNoticeController` (renames). Verify: AccrualNoticeServiceTest, DeadlineMonitoringEndToEndTest.
- [ ] 6. A screen that prepares the payout (follow-up).
