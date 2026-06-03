# Design: termijnbewaking-dwangsom-engine-06-dwangsom-calculation

## Scope of this member

The dwangsom staffel engine, its daily-scan wiring, and the beschikking stop. The actual payment signal (`DwangsomUitbetaling`) is member 07 — this member calls `prepareBetaling()` as the hand-off but does not implement it.

## Approach

### DwangsomTariff constants
`TARIFF_TIER_1_DAYS = 14`, `TARIFF_TIER_1_RATE = 23`, `TARIFF_TIER_2_DAYS = 14`, `TARIFF_TIER_2_RATE = 35`, `TARIFF_TIER_3_RATE = 45`, `GRACE_DAYS = 14`, `PLAFOND = 1442`. The Woo regime override (`afwijkendDwangsomRegime`) is read from the `TermijnDefinitie`.

### DwangsomCalculationService.calculateDaily(dwangsomBerekeningId)
- Read `startDatum` (grace end), `huidigeDag`, `cumulatievBedrag`, `plafondBereikt`.
- If `plafondBereikt` → no accrual.
- Else determine the tier from `huidigeDag` (1–14 → €23, 15–28 → €35, 29+ → €45), add the day's tariff to `cumulatievBedrag` (no retroactive recalculation of prior tiers).
- If `cumulatievBedrag ≥ plafond` → cap at plafond, set `plafondBereikt = true`.
- Advance `huidigeDag++`, update `dagtarief` and `cumulatievBedrag`.

### Scan wiring (extends member 04 DailyTermijnScanJob)
After the termijn-threshold passes, query all `DwangsomBerekening` with `status = lopend` and call `calculateDaily()`; emit `dwangsom-accrued` with `{zaakId, dailyIncrement, newCumulativeBedrag}`.

### Beschikking stop (TermijnService)
`markTermijnCompleted(termijnInstanceId, beschikkingDatum, documentLink)` → set `TermijnInstance.status = voltooid`, record `voltooi` event, stop the related `DwangsomBerekening` with `status = gestopt-wegens-beschikking` and `definitievBedrag = cumulatievBedrag`, then call `DwangsomUitbetalingService.prepareBetaling()` (member 07).

## Security (ADR-005)

Accrual runs in the system scan context. Beschikking registration is a per-case handler action gated by the member-10 controller. Amounts are server-computed; no client-supplied bedrag is trusted.

## Tests

Unit: tier transitions at day 15 and day 29 are exact; plafond enforced with no overshoot; beschikking locks `definitievBedrag`. Integration: scan accrues each lopend berekening and emits `dwangsom-accrued`.
