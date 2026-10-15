# accruing-amount Specification (delta)

## Purpose

A daily-accruing amount (the Awb 4:17 penalty) is a stored OpenRegister calculation; dossiq only stops the clock and locks the result.

## ADDED Requirements

### Requirement: The penalty accrues as a stored calculation (REQ-ACA-001)

`penaltyPaymentCalculation` SHALL compute `currentDag`, `cumulativeAmount`, `dailyRate` and `plafondBereikt` as materialised calculations under `configuration.x-openregister-calculations`, counting whole days from `startDate` to `stoppedOn` (today while empty), accruing over `rateBands` (the Awb bands 2300/3500/4500 cents at 14 and 28 days when empty), capped at `plafondCalculated`.

#### Scenario: Five days at the Awb bands

- GIVEN a running penalty whose start date was five days ago, without rate bands
- WHEN OpenRegister materialises it
- THEN `currentDag` is 5 and `cumulativeAmount` is 11500

### Requirement: Settling stops the clock and locks the amount (REQ-ACA-002)

`AccruingAmountSettler::settle()` SHALL write `stoppedOn` (keeping an earlier one) and the new status, and SHALL lock the amount OpenRegister materialised on that save into the definitive field.

#### Scenario: A decision stops the penalty

- GIVEN a running penalty that accrued 11500
- WHEN the decision is filed on 2026-04-03
- THEN the penalty reads status `gestopt-wegens-decision`, `stoppedOn` 2026-04-03 and `definitiveAmount` 11500
