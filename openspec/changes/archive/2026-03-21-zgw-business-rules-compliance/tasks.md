## 1. Eindstatus Detection and Side Effects (zrc-007a/b/q)

- [x] 1.1 In `ZgwZrcRulesService`, add `detectEindstatus()` helper that fetches all statustypes for a zaaktype and checks whether the given statustype has the highest `volgnummer`
- [x] 1.2 On status creation, call `detectEindstatus()` as fallback when `isEindstatus` is absent; if eindstatus, set `zaak.einddatum = now()` and persist via ObjectService
- [x] 1.3 Before allowing eindstatus creation (zrc-007q), query all ZaakInformatieObjecten for the zaak and return HTTP 400 if any has `indicatieGebruiksrecht === null`
- [x] 1.4 After eindstatus is confirmed (zrc-007b), cascade-update all linked informatieobjecten to `indicatieGebruiksrecht = true` via ObjectService; log but do not abort on individual failures

## 2. Authorization and Scope Checks (zrc-006, zrc-008c)

- [x] 2.1 In `ZgwZrcRulesService`, add `filterZakenForConsumer()` that reads consumer `authorizations` from `ZgwAuthMiddleware` context and injects `_filters` for allowed zaaktypen and `maxVertrouwelijkheidaanduiding` into the ObjectService query
- [x] 2.2 Add vertrouwelijkheidaanduiding comparison table to `ZgwRulesBase` for ordered severity checking (openbaar < beperkt_openbaar < ... < zeer_geheim)
- [x] 2.3 Apply authorization filter in the `GET /zaken` handler; fall back to unfiltered when no authorization context is present
- [x] 2.4 In zaak PATCH handling, detect reopen attempt (removal of `einddatum`); check consumer has `zaken.heropenen` scope; return HTTP 403 if not present (zrc-008c)

## 3. Validation and Error Code Fixes (zrc-010, zrc-013a, zrc-002, zrc-015, zrc-016/018/019/020)

- [x] 3.1 Fix `communicatiekanaal` validation in `ZgwZrcRulesService` to return error code `invalid-resource` instead of `bad-url` (zrc-010)
- [x] 3.2 Fix `hoofdzaak` not-found error code to `does-not-exist` instead of `no_match` (zrc-013a)
- [x] 3.3 Add `identificatie` + `bronorganisatie` uniqueness check on zaak create/update; return HTTP 400 on duplicate (zrc-002)
- [x] 3.4 Add `productenOfDiensten` subset validation against zaaktype's allowed list on zaak create/update; return HTTP 400 on invalid entry (zrc-015)
- [x] 3.5 Add cross-zaaktype validation for statustype, resultaattype, eigenschap, and roltype sub-resources — reject if the referenced type belongs to a different zaaktype (zrc-016/018/019/020)

## 4. Business Rule Side Effects (zrc-021, zrc-009, zrc-005b/023h)

- [x] 4.1 On resultaat creation, derive `zaak.archiefactiedatum` from `resultaattype.brondatumArchiefprocedure` and persist on the zaak (zrc-021)
- [x] 4.2 On zaak create/update, always fetch and apply `zaaktype.vertrouwelijkheidaanduiding` to override template default; fall back to request value only when zaaktype field is absent (zrc-009)
- [x] 4.3 On ZIO delete, query DRC register for matching OIO and delete it via ObjectService (zrc-005b)
- [x] 4.4 On zaak delete, cascade-delete all ZIOs and their corresponding OIOs from DRC register (zrc-023h)

## 5. Performance Optimisation

- [x] 5.1 Replace per-object zaaktype lookups in enrichment loop with a single batched `ObjectService::getObjects()` call with `_filters[uuid][in]` and `_limit=1000`
- [x] 5.2 Replace per-object statustype lookups with batched query; map results back in-memory
- [x] 5.3 Replace per-object resultaattype and roltype lookups with batched queries
- [ ] 5.4 Profile representative endpoints (`GET /zaken`, `GET /zaken/{uuid}`, `POST /zaken`) before and after; confirm p50 latency < 200 ms

## 6. Tests

- [x] 6.1 Add unit tests for `detectEindstatus()` helper covering volgnummer fallback and explicit `isEindstatus`
- [x] 6.2 Add unit tests for `filterZakenForConsumer()` covering zaaktype filtering and vertrouwelijkheidaanduiding ordering
- [x] 6.3 Add unit tests for all error-code fixes (zrc-010, zrc-013a, zrc-002, zrc-015, zrc-016–020)
- [x] 6.4 Add unit tests for side effects (archiefactiedatum derivation, vertrouwelijkheidaanduiding override, OIO cascade-delete)
- [ ] 6.5 Run VNG Newman test suite and confirm 353/353 assertions pass with 0 failures
