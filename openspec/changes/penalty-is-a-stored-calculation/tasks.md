# Tasks: penalty-is-a-stored-calculation

- [x] 1.1 `rateBands`, `stoppedOn` and the four calculations on `penaltyPaymentCalculation` in `register.d/60-termijnbewaking.json` and `dossiq_mock_register.json` (REQ-ACA-001). Verify: `PenaltyPaymentCalculationSchemaTest`; the expressions evaluated with OpenRegister's `CalculationEvaluator` at openregister#4571 for 0 to 60 days match the retired service.
- [x] 1.2 `Money\AccruingAmountSettler`, used by `DwangsomController::beschikking`; `registerBezwaar` sets `stoppedOn` (REQ-ACA-002). Verify: `AccruingAmountSettlerTest`, `DeadlineMonitoringEndToEndTest`.
- [x] 1.3 Delete `DwangsomCalculationService` and `syncPenaltyAccrual`. Verify: `TermijnTimerFiredListenerTest::testFireLeavesThePenaltyCalculationToOpenRegister`.
- [ ] 1.4 Live: after openregister#4571 lands, import the register and read a running penalty's `cumulativeAmount` through the API on two days.
