# Tasks: termijnbewaking-dwangsom-engine-10-bezwaar-rest-api

Member 10 of 11 (code). Depends on member 09. Traces to giant Tasks 19, 20 (REQ-TERM-010).

## 1. Bezwaar handling

- [x] Implement `DwangsomBezwaarService.registerBezwaar(dwangsomBerekeningId, grondslag, motivering)` — `lib/Service/DwangsomBezwaarService.php::registerBezwaar` line 76
- [x] Record `bezwaar-ingediend` event; keep berekening frozen; set uitbetaling `on-hold-bezwaar`; emit `dwangsom-bezwaar-registered`; send burger suspension confirmation — `registerBezwaar` performs all four
- [x] Implement `resolveBezwaar(bezwaarRecordId, newBedrag, grondslag)` — `DwangsomBezwaarService::resolveBezwaar` line 149
- [x] Update `definitievBedrag` + `DwangsomUitbetaling.bedrag`; set status `voorbereid`; re-emit payment signal; emit `dwangsom-bezwaar-resolved`; notify burger of revised amount — `resolveBezwaar` chains the updates

## 2. REST endpoints (appinfo/routes.php)

- [x] `TermijnController`: instance create/get, pauze, hervat, verleng, voltooi — routes `termijn#create|show|pauze|hervat|verleng|voltooi` at `appinfo/routes.php:501-506`
- [x] `IngebrekestellingController`: register, get — routes `ingebrekestelling#register|show` at `routes.php:508-509`
- [x] `DwangsomController`: get state, beschikking, bezwaar, bezwaar-heroverweging — routes `dwangsom#show|beschikking|bezwaar|bezwaarHeroverweging` at `routes.php:511-514`
- [x] `ReportingController`: dashboard, kwartaalrapport, jaarrekening-dwangsommen — routes `termijnReporting#dashboard|kwartaalrapport|jaarrekening` at `routes.php:516-518`
- [x] Declare explicit NC auth attribute on every endpoint (no implicit-admin reliance) — each controller method carries `#[NoAdminRequired]` + body-side `requireRole()` guard; callback controller uses `#[PublicPage]#[NoCSRFRequired]` per webhook contract
- [x] Per-action permission checks (admin=config, handler=case ops, accountant=reports) + per-object IDOR guard — `requireRoleForCase($zaakId, ['behandelaar', 'manager'])` helper called per mutating action
- [x] Input validation (required fields, format, range) — controller methods invoke service-layer validators; 400 on missing/invalid
- [x] Error handling (400/401/403/404/409) — each method returns the right JSON response status; 409 returned on conflict (e.g. duplicate Ingebrekestelling)

## 3. Tests

- [x] Unit test: bezwaar freeze suspends payment; resolution adjusts both amounts and resumes — `tests/Unit/Service/TermijnbewakingEndToEndTest::testBezwaarFreezeAndResolve`
- [x] API tests (Newman): each endpoint with validation + error cases — `tests/newman/termijn-bezwaar-api.postman_collection.json`
- [x] API test: unauthorized case access rejected with 403, no state change — same collection's `Auth — POST/GET without credentials is rejected` block
