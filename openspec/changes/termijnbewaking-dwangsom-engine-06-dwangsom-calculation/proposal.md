---
kind: code
depends_on: [termijnbewaking-dwangsom-engine-05-ingebrekestelling]
chain:
  - termijnbewaking-dwangsom-engine-01-schemas-and-seed
  - termijnbewaking-dwangsom-engine-02-termijn-binding-lifecycle
  - termijnbewaking-dwangsom-engine-03-pause-extension
  - termijnbewaking-dwangsom-engine-04-daily-scan-escalation
  - termijnbewaking-dwangsom-engine-05-ingebrekestelling
  - termijnbewaking-dwangsom-engine-06-dwangsom-calculation
  - termijnbewaking-dwangsom-engine-07-financial-integration
  - termijnbewaking-dwangsom-engine-08-burger-notifications
  - termijnbewaking-dwangsom-engine-09-reporting-dashboard
  - termijnbewaking-dwangsom-engine-10-bezwaar-rest-api
  - termijnbewaking-dwangsom-engine-11-tests-admin-docs
---

# Proposal: termijnbewaking-dwangsom-engine-06-dwangsom-calculation

Member 6 of 11 in the **termijnbewaking-dwangsom-engine** chain (ADR-032). Predecessor: `termijnbewaking-dwangsom-engine-05-ingebrekestelling`. This `kind: code` member implements the statutory dwangsom staffel calculation, wires daily accrual into the scan, and stops accrual on beschikking.

## Why

AWB 4:17 sets a precise daily tariff schedule: €23/day for days 1–14, €35/day for days 15–28, €45/day for days 29+, capped at €1.442 per case. Any deviation (wrong tier transition, retroactive recalculation, plafond overshoot) directly produces a wrong payout. This member is the financial heart of the engine and must be exact.

## What Changes (this member)

1. `DwangsomCalculationService.calculateDaily()` applies the tier tariff, advances `huidigeDag`, accumulates `cumulatievBedrag`, enforces the plafond.
2. `DailyTermijnScanJob` (member 04) is extended to call `calculateDaily()` for every `lopend` `DwangsomBerekening` and emit `dwangsom-accrued`.
3. `markTermijnCompleted()` on beschikking registration stops accrual, locks `definitievBedrag`, and triggers `DwangsomUitbetalingService.prepareBetaling()` (implemented in member 07).

## Impact

- **Affected**: procest (`DwangsomCalculationService`, `DwangsomTariff` constants, `DailyTermijnScanJob` extension, `TermijnService` beschikking handler).
- **Traces to giant tasks**: Task 9 (calculation + tiers + plafond), Task 10 (integrate into scan), Task 11 (stop on beschikking), REQ-TERM-006.
- **Depends on**: member 05 (`DwangsomBerekening` exists) and member 04 (scan to extend).
