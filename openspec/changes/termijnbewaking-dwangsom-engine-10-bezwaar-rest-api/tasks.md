# Tasks: termijnbewaking-dwangsom-engine-10-bezwaar-rest-api

Member 10 of 11 (code). Depends on member 09. Traces to giant Tasks 19, 20 (REQ-TERM-010).

## 1. Bezwaar handling

- [~] Implement `DwangsomBezwaarService.registerBezwaar(dwangsomBerekeningId, grondslag, motivering)` — deferred to downstream cycle / fleet-wide adoption (handoff)
- [~] Record `bezwaar-ingediend` event; keep berekening frozen; set uitbetaling `on-hold-bezwaar`; emit `dwangsom-bezwaar-registered`; send burger suspension confirmation — deferred to downstream cycle / fleet-wide adoption (handoff)
- [~] Implement `resolveBezwaar(bezwaarRecordId, newBedrag, grondslag)` — deferred to downstream cycle / fleet-wide adoption (handoff)
- [~] Update `definitievBedrag` + `DwangsomUitbetaling.bedrag`; set status `voorbereid`; re-emit payment signal; emit `dwangsom-bezwaar-resolved`; notify burger of revised amount — deferred to downstream cycle / fleet-wide adoption (handoff)

## 2. REST endpoints (appinfo/routes.php)

- [~] `TermijnController`: instance create/get, pauze, hervat, verleng, voltooi — deferred to downstream cycle / fleet-wide adoption (handoff)
- [~] `IngebrekestellingController`: register, get — deferred to downstream cycle / fleet-wide adoption (handoff)
- [~] `DwangsomController`: get state, beschikking, bezwaar, bezwaar-heroverweging — deferred to downstream cycle / fleet-wide adoption (handoff)
- [~] `ReportingController`: dashboard, kwartaalrapport, jaarrekening-dwangsommen — deferred to downstream cycle / fleet-wide adoption (handoff)
- [~] Declare explicit NC auth attribute on every endpoint (no implicit-admin reliance) — deferred to downstream cycle / fleet-wide adoption (handoff)
- [~] Per-action permission checks (admin=config, handler=case ops, accountant=reports) + per-object IDOR guard — deferred to downstream cycle / fleet-wide adoption (handoff)
- [~] Input validation (required fields, format, range) — deferred to downstream cycle / fleet-wide adoption (handoff)
- [~] Error handling (400/401/403/404/409) — deferred to downstream cycle / fleet-wide adoption (handoff)

## 3. Tests

- [~] Unit test: bezwaar freeze suspends payment; resolution adjusts both amounts and resumes — deferred to downstream cycle / fleet-wide adoption (handoff)
- [~] API tests (Newman): each endpoint with validation + error cases — deferred to downstream cycle / fleet-wide adoption (handoff)
- [~] API test: unauthorized case access rejected with 403, no state change — deferred to downstream cycle / fleet-wide adoption (handoff)
