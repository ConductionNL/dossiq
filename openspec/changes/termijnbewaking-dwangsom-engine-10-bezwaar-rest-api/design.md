# Design: termijnbewaking-dwangsom-engine-10-bezwaar-rest-api

## Scope of this member

`DwangsomBezwaarService` + the REST API layer over the whole chain. The admin TermijnDefinitie UI is member 11.

## Approach

### DwangsomBezwaarService
- `registerBezwaar(dwangsomBerekeningId, grondslag, motivering)` — record a `bezwaar-ingediend` event on the `DwangsomBerekening` (which stays `gestopt-wegens-beschikking`, frozen), set `DwangsomUitbetaling.status = on-hold-bezwaar`, emit `dwangsom-bezwaar-registered`, send burger suspension confirmation (via member-08 service).
- `resolveBezwaar(bezwaarRecordId, newBedrag, grondslag)` — update `DwangsomBerekening.definitievBedrag` and `DwangsomUitbetaling.bedrag` to `newBedrag`, set `DwangsomUitbetaling.status = voorbereid` (re-emit payment signal via member-07 service), emit `dwangsom-bezwaar-resolved`, notify burger of the revised amount.

### REST API (ADR-016: routes only via appinfo/routes.php)
- `TermijnController`: instance create/get, pauze, hervat, verleng, voltooi.
- `IngebrekestellingController`: register, get.
- `DwangsomController`: get state, beschikking, bezwaar, bezwaar-heroverweging.
- `ReportingController`: dashboard, kwartaalrapport, jaarrekening-dwangsommen.
- Input validation (required fields, format, range). Permission checks per action (ADR-023): admin for config, handler for case ops, accountant for reports. Error handling: 400/401/403/404/409.

## Security (ADR-005, ADR-023)

Every endpoint declares an explicit NC auth attribute (no implicit-admin reliance) and performs an in-method per-object/role authorization check — no IDOR. Bezwaar registration and resolution are handler/manager actions on a specific dwangsom; the service derives state server-side and never trusts a client-supplied amount beyond the explicit `newBedrag` on the authorized resolution path.

## Tests

Unit: bezwaar freeze suspends payment; resolution adjusts both amounts and resumes; endpoint authorization matrix (handler vs admin vs accountant). API tests (Newman) over each endpoint with validation + error cases.
