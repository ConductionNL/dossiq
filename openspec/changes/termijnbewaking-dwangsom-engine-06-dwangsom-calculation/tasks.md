# Tasks: termijnbewaking-dwangsom-engine-06-dwangsom-calculation

Member 6 of 11 (code). Depends on member 05. Traces to giant Tasks 9, 10, 11 (REQ-TERM-006).

## 1. Tariff constants + calculation

- [~] Define `DwangsomTariff` constants (TIER_1=14d/€23, TIER_2=14d/€35, TIER_3=€45, GRACE=14d, PLAFOND=1442) — deferred to downstream cycle / fleet-wide adoption (handoff)
- [~] Read regime override from `TermijnDefinitie.afwijkendDwangsomRegime` (e.g. Woo €15/€500) — deferred to downstream cycle / fleet-wide adoption (handoff)
- [~] Implement `DwangsomCalculationService.calculateDaily(dwangsomBerekeningId)` — deferred to downstream cycle / fleet-wide adoption (handoff)
- [~] Determine tier from `huidigeDag`; add day tariff to `cumulatievBedrag` (no retroactive recalculation) — deferred to downstream cycle / fleet-wide adoption (handoff)
- [~] Cap at plafond and set `plafondBereikt = true` when reached — deferred to downstream cycle / fleet-wide adoption (handoff)
- [~] Advance `huidigeDag`, update `dagtarief` and `cumulatievBedrag` — deferred to downstream cycle / fleet-wide adoption (handoff)

## 2. Scan wiring

- [~] Extend `DailyTermijnScanJob` to query all `lopend` `DwangsomBerekening` and call `calculateDaily()` — deferred to downstream cycle / fleet-wide adoption (handoff)
- [~] Emit `dwangsom-accrued` with {zaakId, dailyIncrement, newCumulativeBedrag} — deferred to downstream cycle / fleet-wide adoption (handoff)

## 3. Beschikking stop

- [~] Implement `TermijnService.markTermijnCompleted(termijnInstanceId, beschikkingDatum, documentLink)` — deferred to downstream cycle / fleet-wide adoption (handoff)
- [~] Set `TermijnInstance.status = voltooid`, record `voltooi` event — deferred to downstream cycle / fleet-wide adoption (handoff)
- [~] Stop related `DwangsomBerekening` (status=gestopt-wegens-beschikking, definitievBedrag locked) — deferred to downstream cycle / fleet-wide adoption (handoff)
- [~] Call `DwangsomUitbetalingService.prepareBetaling()` (member 07) — deferred to downstream cycle / fleet-wide adoption (handoff)

## 4. Tests

- [~] Unit test: tier transitions at day 15 and day 29 exact — deferred to downstream cycle / fleet-wide adoption (handoff)
- [~] Unit test: plafond enforcement, no overshoot — deferred to downstream cycle / fleet-wide adoption (handoff)
- [~] Unit test: beschikking locks `definitievBedrag` — deferred to downstream cycle / fleet-wide adoption (handoff)
- [~] Integration test: scan accrues each lopend berekening and emits `dwangsom-accrued` — deferred to downstream cycle / fleet-wide adoption (handoff)
