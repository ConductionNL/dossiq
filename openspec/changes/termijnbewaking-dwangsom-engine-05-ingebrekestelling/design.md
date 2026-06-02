# Design: termijnbewaking-dwangsom-engine-05-ingebrekestelling

## Scope of this member

`IngebrekestellingService`: registration, overschrijding validation, grace-period `DwangsomBerekening` creation, and the one-dwangsom-per-termijn guard. The daily accrual against the created `DwangsomBerekening` is member 06. The burger receipt notification text is rendered in member 08; this member emits the trigger.

## Approach

- **Data access (ADR-001)**: via OpenRegister `ObjectService`.
- `registerIngebrekestelling(termijnInstanceId, ontvangstDatum, kanaal, documentLink)`:
  - Validate `TermijnInstance.status = overschreden` AND `einddatumActueel < ontvangstDatum`.
  - Valid → `gevalideerd = true`, `geldigheidStatus = geldig`; if `relevantIngbrekes` is unset, set it to this notice and auto-create a `DwangsomBerekening` with `startDatum = ontvangstDatum + 14`, `status = lopend`, `huidigeDag = 0`; emit `ingebrekestelling-ontvangen`; emit burger-receipt notification trigger.
  - Premature → `gevalideerd = false`, `geldigheidStatus = premaat`; return advice; no `DwangsomBerekening`.
- **One-dwangsom guard**: if `relevantIngbrekes` is already set, register the new `Ingebrekestelling` for the record but do NOT create a second `DwangsomBerekening`; return an info message naming the first notice's date.

## Security (ADR-005)

Registration is a per-case handler action; the controller (member 10) enforces per-object authorization (ADR-023). Validation is server-authoritative — the client cannot assert `gevalideerd = true`; the service derives it from the instance state.

## Tests

Unit: valid overschreden registration creates `DwangsomBerekening` with correct grace start; premature registration rejected; second notice does not spawn a second berekening.
