# Design: termijnbewaking-dwangsom-engine-07-financial-integration

## Scope of this member

`DwangsomUitbetalingService.prepareBetaling()` + the openconnector callback controller. The burger-facing payment-confirmation message text is rendered in member 08; this member emits the trigger.

## Approach

### DwangsomUitbetalingService.prepareBetaling(dwangsomBerekeningId)
- Fetch `definitievBedrag`, the parent `Ingebrekestelling.ontvangstDatum`, and the zaak.
- Resolve burger payment details (`rekeninghouderNaam`, `iban`) from the aanvraag/contact record. Validate IBAN format; raise an error if missing/invalid (no silent skip).
- Create `DwangsomUitbetaling` with `bedrag`, `rekeninghouderNaam`, `iban`, `referentie = zaakId-ontvangstDatum`, `wettelijkeGrondslag = "AWB 4:17 lid 2"`, `betaaldatumUiterlijk = ontvangstDatum + 28 days`, `status = voorbereid`.
- Emit `dwangsom-payment-signal` to openconnector with full metadata `{zaakId, dwangsomBedrag, rekeninghouderNaam, iban, referentie, wettelijkeGrondslag, betaaldeadline, caseLink}`; log the emission to the audit trail.

### OpenconnectorCallbackController
- `POST /api/procest/openconnector/dwangsom-payment-callback`.
- Validate the openconnector webhook signature (ADR-005). Reject unsigned/invalid callbacks.
- Parse `{referentie, status, werkelijkeBetaaldatum, betalingsreferentie}`, look up the `DwangsomUitbetaling` by `referentie` (404 if not found), update `status`/`werkelijkeBetaaldatum`/`betalingsreferentie`.
- On `betaald`: emit `dwangsom-betaald` and trigger the burger payment notification (member 08).

## Security (ADR-005)

The callback endpoint is a webhook: it is `#[PublicPage]`/`#[NoCSRFRequired]` but **authenticated by signature verification**, not left open. The `referentie` lookup is exact-match; an unknown referentie returns 404 without side effects. IBAN is validated before any signal is emitted.

## Tests

Unit: IBAN validation, payload structure, `betaaldatumUiterlijk` math. Integration: mock ERP callback updates the `DwangsomUitbetaling` and emits `dwangsom-betaald`; unknown-referentie callback is rejected.
