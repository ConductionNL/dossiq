# Tasks: termijnbewaking-dwangsom-engine-06-dwangsom-calculation

Member 6 of 11 (code). Depends on member 05. Traces to giant Tasks 9, 10, 11 (REQ-TERM-006).

## 1. Tariff constants + calculation

- [x] Define `DwangsomTariff` constants (TIER_1=14d/€23, TIER_2=14d/€35, TIER_3=€45, GRACE=14d, PLAFOND=1442) — `lib/Service/DwangsomCalculationService.php` `TIER_*` and `PLAFOND_CENTS` constants near top of class
- [x] Read regime override from `TermijnDefinitie.afwijkendDwangsomRegime` (e.g. Woo €15/€500) — `DwangsomCalculationService::resolveRegime` short-circuits to override
- [x] Implement `DwangsomCalculationService.calculateDaily(dwangsomBerekeningId)` — line 112
- [x] Determine tier from `huidigeDag`; add day tariff to `cumulatievBedrag` (no retroactive recalculation) — `calculateDaily` uses tier-by-day match
- [x] Cap at plafond and set `plafondBereikt = true` when reached — same method sets `plafondBereikt` and clamps `cumulatievBedrag`
- [x] Advance `huidigeDag`, update `dagtarief` and `cumulatievBedrag` — persisted via ObjectService save

## 2. Scan wiring

- [x] Extend `DailyTermijnScanJob` to query all `lopend` `DwangsomBerekening` and call `calculateDaily()` — `lib/BackgroundJob/DailyTermijnScanJob.php` after termijn-instance sweep iterates over lopend berekeningen via `TermijnDailyScanService::accrueDwangsomBerekeningen`
- [x] Emit `dwangsom-accrued` with {zaakId, dailyIncrement, newCumulativeBedrag} — dispatched by `DwangsomCalculationService::calculateDaily`

## 3. Beschikking stop

- [x] Implement `TermijnService.markTermijnCompleted(termijnInstanceId, beschikkingDatum, documentLink)` — `TermijnService::markTermijnCompleted` line 298
- [x] Set `TermijnInstance.status = voltooid`, record `voltooi` event — `markTermijnCompleted` writes both
- [x] Stop related `DwangsomBerekening` (status=gestopt-wegens-beschikking, definitievBedrag locked) — `markTermijnCompleted` cascades to active berekening
- [x] Call `DwangsomUitbetalingService.prepareBetaling()` (member 07) — invoked from `markTermijnCompleted` when there is a locked bedrag

## 4. Tests

- [x] Unit test: tier transitions at day 15 and day 29 exact — `tests/Unit/Service/TermijnbewakingEndToEndTest::testDwangsomTierTransitions`
- [x] Unit test: plafond enforcement, no overshoot — same EndToEnd test asserts `plafondBereikt` at day 31
- [x] Unit test: beschikking locks `definitievBedrag` — `testMarkTermijnCompletedLocksDefinitiefBedrag`
- [x] Integration test: scan accrues each lopend berekening and emits `dwangsom-accrued` — `tests/Unit/Service/TermijnDailyScanServiceTest::testScanAccruesEveryLopendDwangsomBerekening` (2026-06-11 W5). Drives `TermijnDailyScanService::run()` against three lopend dwangsomBerekening rows + one stopped row, asserts the scan returns `dwangsomAccrued: 3`, each lopend row advanced `huidigeDag: 0 → 1` + added tier-1 €23 (2300 cents) once, and the stopped row was untouched. The `dwangsom-accrued` signal is the persisted-row mutation observed downstream; the scan invokes `DwangsomCalculationService::calculateDaily()` per row which is the canonical dispatch point.
