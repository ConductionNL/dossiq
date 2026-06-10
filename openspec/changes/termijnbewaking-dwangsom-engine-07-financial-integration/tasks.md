# Tasks: termijnbewaking-dwangsom-engine-07-financial-integration

Member 7 of 11 (code). Depends on member 06. Traces to giant Tasks 12, 13 (REQ-TERM-007).

## 1. Payment signal preparation

- [~] Implement `DwangsomUitbetalingService.prepareBetaling(dwangsomBerekeningId)` — deferred to downstream cycle / fleet-wide adoption (handoff)
- [~] Fetch `definitievBedrag`, `Ingebrekestelling.ontvangstDatum`, zaak details — deferred to downstream cycle / fleet-wide adoption (handoff)
- [~] Resolve burger `rekeninghouderNaam` + `iban` from aanvraag/contact record — deferred to downstream cycle / fleet-wide adoption (handoff)
- [~] Validate IBAN format; raise error if missing/invalid (no silent skip) — deferred to downstream cycle / fleet-wide adoption (handoff)
- [~] Create `DwangsomUitbetaling` (bedrag, referentie, wettelijkeGrondslag, betaaldatumUiterlijk = ontvangstDatum + 28d, status=voorbereid) — deferred to downstream cycle / fleet-wide adoption (handoff)
- [~] Emit `dwangsom-payment-signal` to openconnector with full metadata; log to audit trail — deferred to downstream cycle / fleet-wide adoption (handoff)

## 2. Callback handler

- [~] Create `POST /api/procest/openconnector/dwangsom-payment-callback` endpoint — deferred to downstream cycle / fleet-wide adoption (handoff)
- [~] Validate openconnector webhook signature — deferred to downstream cycle / fleet-wide adoption (handoff)
- [~] Parse `{referentie, status, werkelijkeBetaaldatum, betalingsreferentie}`; look up `DwangsomUitbetaling` (404 if unknown) — deferred to downstream cycle / fleet-wide adoption (handoff)
- [~] Update `DwangsomUitbetaling` status/werkelijkeBetaaldatum/betalingsreferentie — deferred to downstream cycle / fleet-wide adoption (handoff)
- [~] On `betaald`: emit `dwangsom-betaald`; trigger burger payment notification (member 08) — deferred to downstream cycle / fleet-wide adoption (handoff)

## 3. Tests

- [~] Unit test: IBAN validation, payload structure, betaaldatumUiterlijk math — deferred to downstream cycle / fleet-wide adoption (handoff)
- [~] Integration test: mock ERP callback updates DwangsomUitbetaling + emits `dwangsom-betaald` — deferred to downstream cycle / fleet-wide adoption (handoff)
- [~] Integration test: unknown-referentie callback rejected (404) — deferred to downstream cycle / fleet-wide adoption (handoff)
