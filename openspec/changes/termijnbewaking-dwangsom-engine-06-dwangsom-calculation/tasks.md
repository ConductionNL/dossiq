# Tasks: termijnbewaking-dwangsom-engine-06-dwangsom-calculation

> **Build status (hydra audit).** Greenfield. No TermijnDefinitie/TermijnInstance/TermijnGebeurtenis/Ingebrekestelling/Dwangsom schemas, no termijn-binding lifecycle, no daily-scan escalation daemon, no dwangsom calculation/financial integration, no burger notifications, no reporting/REST-API surfaces on dev. The 11-member chain delivers the AWB termijnbewaking + dwangsom engine from scratch. Tasks stay [ ] as genuine forward work.

Member 6 of 11 (code). Depends on member 05. Traces to giant Tasks 9, 10, 11 (REQ-TERM-006).

## 1. Tariff constants + calculation

- [ ] Define `DwangsomTariff` constants (TIER_1=14d/€23, TIER_2=14d/€35, TIER_3=€45, GRACE=14d, PLAFOND=1442)
- [ ] Read regime override from `TermijnDefinitie.afwijkendDwangsomRegime` (e.g. Woo €15/€500)
- [ ] Implement `DwangsomCalculationService.calculateDaily(dwangsomBerekeningId)`
- [ ] Determine tier from `huidigeDag`; add day tariff to `cumulatievBedrag` (no retroactive recalculation)
- [ ] Cap at plafond and set `plafondBereikt = true` when reached
- [ ] Advance `huidigeDag`, update `dagtarief` and `cumulatievBedrag`

## 2. Scan wiring

- [ ] Extend `DailyTermijnScanJob` to query all `lopend` `DwangsomBerekening` and call `calculateDaily()`
- [ ] Emit `dwangsom-accrued` with {zaakId, dailyIncrement, newCumulativeBedrag}

## 3. Beschikking stop

- [ ] Implement `TermijnService.markTermijnCompleted(termijnInstanceId, beschikkingDatum, documentLink)`
- [ ] Set `TermijnInstance.status = voltooid`, record `voltooi` event
- [ ] Stop related `DwangsomBerekening` (status=gestopt-wegens-beschikking, definitievBedrag locked)
- [ ] Call `DwangsomUitbetalingService.prepareBetaling()` (member 07)

## 4. Tests

- [ ] Unit test: tier transitions at day 15 and day 29 exact
- [ ] Unit test: plafond enforcement, no overshoot
- [ ] Unit test: beschikking locks `definitievBedrag`
- [ ] Integration test: scan accrues each lopend berekening and emits `dwangsom-accrued`
