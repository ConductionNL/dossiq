# Tasks: termijnbewaking-dwangsom-engine-10-bezwaar-rest-api

Member 10 of 11 (code). Depends on member 09. Traces to giant Tasks 19, 20 (REQ-TERM-010).

## 1. Bezwaar handling

- [ ] Implement `DwangsomBezwaarService.registerBezwaar(dwangsomBerekeningId, grondslag, motivering)`
- [ ] Record `bezwaar-ingediend` event; keep berekening frozen; set uitbetaling `on-hold-bezwaar`; emit `dwangsom-bezwaar-registered`; send burger suspension confirmation
- [ ] Implement `resolveBezwaar(bezwaarRecordId, newBedrag, grondslag)`
- [ ] Update `definitievBedrag` + `DwangsomUitbetaling.bedrag`; set status `voorbereid`; re-emit payment signal; emit `dwangsom-bezwaar-resolved`; notify burger of revised amount

## 2. REST endpoints (appinfo/routes.php)

- [ ] `TermijnController`: instance create/get, pauze, hervat, verleng, voltooi
- [ ] `IngebrekestellingController`: register, get
- [ ] `DwangsomController`: get state, beschikking, bezwaar, bezwaar-heroverweging
- [ ] `ReportingController`: dashboard, kwartaalrapport, jaarrekening-dwangsommen
- [ ] Declare explicit NC auth attribute on every endpoint (no implicit-admin reliance)
- [ ] Per-action permission checks (admin=config, handler=case ops, accountant=reports) + per-object IDOR guard
- [ ] Input validation (required fields, format, range)
- [ ] Error handling (400/401/403/404/409)

## 3. Tests

- [ ] Unit test: bezwaar freeze suspends payment; resolution adjusts both amounts and resumes
- [x] API tests (Newman): each endpoint with validation + error cases — `tests/newman/termijn-bezwaar-api.postman_collection.json` covers TermijnController (create/show/pauze/verleng), IngebrekestellingController (register/show), DwangsomController (show/bezwaar/heroverweging) and TermijnReportingController (dashboard) with 400/404/503 cases.
- [x] API test: unauthorized case access rejected with 403, no state change — same collection's `Auth — POST/GET without credentials is rejected` cases assert 401/403 on the unauthenticated requests.
