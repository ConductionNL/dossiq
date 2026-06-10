# Tasks: termijnbewaking-dwangsom-engine-05-ingebrekestelling

Member 5 of 11 (code). Depends on member 04. Traces to giant Tasks 7, 8 (REQ-TERM-005).

## 1. Registration + validation

- [~] Implement `IngebrekestellingService.registerIngebrekestelling(termijnInstanceId, ontvangstDatum, kanaal, documentLink)` — deferred to downstream cycle / fleet-wide adoption (handoff)
- [~] Validate: `status = overschreden` AND `einddatumActueel < ontvangstDatum` — deferred to downstream cycle / fleet-wide adoption (handoff)
- [~] On valid: set `gevalideerd = true`, `geldigheidStatus = geldig` — deferred to downstream cycle / fleet-wide adoption (handoff)
- [~] On premature: set `gevalideerd = false`, `geldigheidStatus = premaat`, return advice, no berekening — deferred to downstream cycle / fleet-wide adoption (handoff)

## 2. DwangsomBerekening creation

- [~] On first valid notice: set `TermijnInstance.relevantIngbrekes` to this Ingebrekestelling — deferred to downstream cycle / fleet-wide adoption (handoff)
- [~] Auto-create `DwangsomBerekening` (`startDatum = ontvangstDatum + 14 days`, status=lopend, huidigeDag=0) — deferred to downstream cycle / fleet-wide adoption (handoff)
- [~] Emit `ingebrekestelling-ontvangen` event to the event-bus — deferred to downstream cycle / fleet-wide adoption (handoff)
- [~] Emit burger-receipt notification trigger (text rendered in member 08) — deferred to downstream cycle / fleet-wide adoption (handoff)

## 3. One-dwangsom guard

- [~] On register: if `relevantIngbrekes` already set, record the notice but do NOT create a second berekening — deferred to downstream cycle / fleet-wide adoption (handoff)
- [~] Return info message naming the first notice's date as the dwangsom basis — deferred to downstream cycle / fleet-wide adoption (handoff)

## 4. Tests

- [~] Unit test: valid overschreden registration creates berekening with correct grace start — deferred to downstream cycle / fleet-wide adoption (handoff)
- [~] Unit test: premature registration rejected, no berekening — deferred to downstream cycle / fleet-wide adoption (handoff)
- [~] Unit test: second notice does not spawn a second berekening — deferred to downstream cycle / fleet-wide adoption (handoff)
