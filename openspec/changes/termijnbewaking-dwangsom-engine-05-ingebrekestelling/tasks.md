# Tasks: termijnbewaking-dwangsom-engine-05-ingebrekestelling

Member 5 of 11 (code). Depends on member 04. Traces to giant Tasks 7, 8 (REQ-TERM-005).

## 1. Registration + validation

- [ ] Implement `IngebrekestellingService.registerIngebrekestelling(termijnInstanceId, ontvangstDatum, kanaal, documentLink)`
- [ ] Validate: `status = overschreden` AND `einddatumActueel < ontvangstDatum`
- [ ] On valid: set `gevalideerd = true`, `geldigheidStatus = geldig`
- [ ] On premature: set `gevalideerd = false`, `geldigheidStatus = premaat`, return advice, no berekening

## 2. DwangsomBerekening creation

- [ ] On first valid notice: set `TermijnInstance.relevantIngbrekes` to this Ingebrekestelling
- [ ] Auto-create `DwangsomBerekening` (`startDatum = ontvangstDatum + 14 days`, status=lopend, huidigeDag=0)
- [ ] Emit `ingebrekestelling-ontvangen` event to the event-bus
- [ ] Emit burger-receipt notification trigger (text rendered in member 08)

## 3. One-dwangsom guard

- [ ] On register: if `relevantIngbrekes` already set, record the notice but do NOT create a second berekening
- [ ] Return info message naming the first notice's date as the dwangsom basis

## 4. Tests

- [ ] Unit test: valid overschreden registration creates berekening with correct grace start
- [ ] Unit test: premature registration rejected, no berekening
- [ ] Unit test: second notice does not spawn a second berekening
