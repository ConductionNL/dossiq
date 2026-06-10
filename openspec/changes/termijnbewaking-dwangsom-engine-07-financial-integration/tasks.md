# Tasks: termijnbewaking-dwangsom-engine-07-financial-integration

Member 7 of 11 (code). Depends on member 06. Traces to giant Tasks 12, 13 (REQ-TERM-007).

## 1. Payment signal preparation

- [ ] Implement `DwangsomUitbetalingService.prepareBetaling(dwangsomBerekeningId)`
- [ ] Fetch `definitievBedrag`, `Ingebrekestelling.ontvangstDatum`, zaak details
- [ ] Resolve burger `rekeninghouderNaam` + `iban` from aanvraag/contact record
- [ ] Validate IBAN format; raise error if missing/invalid (no silent skip)
- [ ] Create `DwangsomUitbetaling` (bedrag, referentie, wettelijkeGrondslag, betaaldatumUiterlijk = ontvangstDatum + 28d, status=voorbereid)
- [ ] Emit `dwangsom-payment-signal` to openconnector with full metadata; log to audit trail

## 2. Callback handler

- [ ] Create `POST /api/procest/openconnector/dwangsom-payment-callback` endpoint
- [ ] Validate openconnector webhook signature
- [ ] Parse `{referentie, status, werkelijkeBetaaldatum, betalingsreferentie}`; look up `DwangsomUitbetaling` (404 if unknown)
- [ ] Update `DwangsomUitbetaling` status/werkelijkeBetaaldatum/betalingsreferentie
- [ ] On `betaald`: emit `dwangsom-betaald`; trigger burger payment notification (member 08)

## 3. Tests

- [ ] Unit test: IBAN validation, payload structure, betaaldatumUiterlijk math
- [ ] Integration test: mock ERP callback updates DwangsomUitbetaling + emits `dwangsom-betaald`
- [ ] Integration test: unknown-referentie callback rejected (404)
