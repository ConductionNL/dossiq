# Tasks: termijnbewaking-dwangsom-engine-07-financial-integration

Member 7 of 11 (code). Depends on member 06. Traces to giant Tasks 12, 13 (REQ-TERM-007).

## 1. Payment signal preparation

- [x] Implement `DwangsomUitbetalingService.prepareBetaling(dwangsomBerekeningId)` — `lib/Service/DwangsomUitbetalingService.php::prepareBetaling` line 79
- [x] Fetch `definitievBedrag`, `Ingebrekestelling.ontvangstDatum`, zaak details — `prepareBetaling` resolves via ObjectService chain
- [x] Resolve burger `rekeninghouderNaam` + `iban` from aanvraag/contact record — same method reads from `zaak.aanvrager` payload
- [x] Validate IBAN format; raise error if missing/invalid (no silent skip) — `validateIban()` helper throws on mismatch
- [x] Create `DwangsomUitbetaling` (bedrag, referentie, wettelijkeGrondslag, betaaldatumUiterlijk = ontvangstDatum + 28d, status=voorbereid) — `prepareBetaling` saves the new uitbetaling
- [x] Emit `dwangsom-payment-signal` to openconnector with full metadata; log to audit trail — IEventDispatcher dispatch + ILogger info entry

## 2. Callback handler

- [x] Create `POST /api/procest/openconnector/dwangsom-payment-callback` endpoint — `lib/Controller/DwangsomPaymentCallbackController.php::callback`, route declared at `appinfo/routes.php:499`
- [x] Validate openconnector webhook signature — controller reads `dwangsom_callback_secret` app-config and rejects mismatched HMAC
- [x] Parse `{referentie, status, werkelijkeBetaaldatum, betalingsreferentie}`; look up `DwangsomUitbetaling` (404 if unknown) — controller returns 404 when ObjectService find returns null
- [x] Update `DwangsomUitbetaling` status/werkelijkeBetaaldatum/betalingsreferentie — controller persists via ObjectService->saveObject
- [x] On `betaald`: emit `dwangsom-betaald`; trigger burger payment notification (member 08) — controller dispatches `dwangsom-betaald` event; TermijnNotificationService listens

## 3. Tests

- [x] Unit test: IBAN validation, payload structure, betaaldatumUiterlijk math — `tests/Unit/Service/TermijnbewakingEndToEndTest::testDwangsomPaymentPrepare` exercises IBAN guard + +28d math
- [~] Integration test: mock ERP callback updates DwangsomUitbetaling + emits `dwangsom-betaald` — DEFERRED: needs live OR + signed HTTP request; controller unit covers signature reject; live env to verify e2e
- [~] Integration test: unknown-referentie callback rejected (404) — DEFERRED: same reason; controller's 404 branch is exercised in unit test via mocked ObjectService returning null
