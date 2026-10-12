---
kind: code
depends_on: []
---

# Proposal: penalty-is-a-stored-calculation

## Why

ADR-118 C16: the Awb 4:17 penalty for a late decision was PHP in dossiq (`DwangsomCalculationService`, about 470 lines): a per-day loop over three bands, a ceiling, a deviating flat rate read from the term definition, and a sync that the timer listener ran on every fire. That arithmetic is the shape of any stepped daily amount, so it belongs in OpenRegister's calculation engine as configuration. openregister#4571 adds the `graduated` and `bandRate` operators that make it expressible.

## What changes

- `penaltyPaymentCalculation` gains `rateBands` (array of `{upTo, rate}` in cents, empty means the Awb bands) and `stoppedOn` (date), and carries four materialised calculations under `configuration.x-openregister-calculations`: `currentDag`, `cumulativeAmount` (`graduated` over the bands, capped at `plafondCalculated`), `dailyRate` (`bandRate`) and `plafondBereikt`. OpenRegister's temporal sweep keeps running rows current; a stopped row reads `stoppedOn` and stays put.
- `NoticeOfDefaultService` writes `rateBands` (none for the Awb regime, one flat band for a deviating regime) and no longer writes the calculated fields.
- New generic `Money\AccruingAmountSettler`: writes `stoppedOn` and the status, and locks the re-materialised amount into `definitiveAmount`. `DwangsomController::beschikking` uses it; `DwangsomBezwaarService::registerBezwaar` sets `stoppedOn` too.
- Deleted: `DwangsomCalculationService` and its test; `TermijnTimerFiredListener::syncPenaltyAccrual`.

## Depends on

openregister#4571 (`graduated`, `bandRate`). Until it lands, OpenRegister refuses the expression as an unknown operator on import.

## Known gaps

- Running `afwijkend` rows written before this change have no `rateBands` and accrue at the Awb bands from now on (dev data only; a backfill repair if wanted).
- `currentDag` keeps counting past the ceiling; the amount does not.
