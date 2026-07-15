# Design: brk-woz-register-adapters

> Verified against procest HEAD (worktree `brk-woz`, branch `wip/brk-woz-register-adapters`,
> based on `origin/development` @ `b2012bab3`, which includes the freshly-merged BAG adapter
> (`bag-register-adapter`, PR #206)) on 2026-07-14. BRK/WOZ API shapes verified via web research
> against Kadaster's published documentation/GitHub specs (no live network access from this
> environment) — see citations inline below.

## Context corrections against the build brief

The build brief assumed the WOZ-waardeloket (`wozwaardeloket.nl`) is "largely open (no key)" and
asked this change to decide between it and an "authoritative WOZ API (key)". Research during this
change corrects that assumption:

| Assumption | Reality (researched) | Consequence |
|---|---|---|
| WOZ-waardeloket is "largely open (no key)" and could be an adapter binding target | The WOZ-waardeloket is a **public web page for individual property lookups** — it explicitly has **no programmatic API** ("niet geheel open en is niet te benaderen via bijvoorbeeld een API" / a `data.overheid.nl` community datarequest is literally *asking* for an API that doesn't exist, and "making WOZ values available as open data would require a legal change"). | It is **not a viable adapter binding target at all** — not "closed" as opposed to "open", but **non-programmatic**. `WozApiAdapter` cannot target it under any tier. |
| An "authoritative WOZ API (key)" exists as a single alternative | Kadaster operates the Haal Centraal **WOZ Bevragen API** (LV-WOZ direct connection) — `X-Api-Key` for the test environment, but **production additionally requires an OIN + PKIOverheid certificate**, and access is **restricted to WOZ data holders (municipalities)** ("uitsluitend beschikbaar voor gemeenten" — data may only be used for tax-collection purposes). | This is procest's actual customer base (municipal VTH/tax), so the restriction is not a practical mismatch — but it means `integration.woz.mode=live` is a heavier lift than BAG's (see "Known trade-offs" below), and MUST be documented as such rather than glossed over. |
| Kadaster publishes a stable public acceptatie/test hostname for WOZ, like it does for BAG/BRK | No such hostname is documented publicly. The WOZ Bevragen OpenAPI spec's only published `servers` entry is a SwaggerHub auto-mock (`virtserver.swaggerhub.com/VNG-sandbox/Waardering-onroerende-zaken/1.0.0`); a real acceptatie/live base URL is issued per-registration. | `WozApiAdapter::DEFAULT_BASE_URL` points at the SwaggerHub mock (`test`-tier smoke-testing only, explicitly documented in the class docblock) rather than a fabricated Kadaster hostname — honest about the gap instead of inventing a plausible-looking URL. |

## Decision 1 — BRK adapter shape mirrors BAG exactly, with a privacy-scoped mapper

`lib/Service/External/Brk/` mirrors `lib/Service/External/Bag/` file-for-file
(`BrkAdapterInterface`, `BrkLookupResult`, `BrkResponseMapper`, `BrkApiAdapter`, `LogBrkAdapter`):

- Two lookups: `lookupByKadastraleAanduiding(kadastraleGemeenteCode, sectie, perceelnummer,
  appartementsrechtVolgnummer?, context)` (search-shaped — `GET /kadastraalonroerendezaken` with
  `kadastraleGemeenteCode`/`sectie`/`perceelnummer`/`appartementsrechtVolgnummer` query params,
  200 + empty `_embedded.kadastraalOnroerendeZaken` on no match, mirrors BAG's `/adressen`) and
  `lookupObject(id, context)` (resource-shaped — `GET /kadastraalonroerendezaken/{id}`, true HTTP
  404 on no match, mirrors BAG's `/panden/{id}`).
- Input validated before any HTTP call: sectie `^[A-Z]{1,2}$`, perceelnummer `^[0-9]{1,5}$`,
  optional appartementsrecht volgnummer `^A[0-9]{1,4}$` (per the documented kadastrale-aanduiding
  4-part structure: kadastrale gemeente, sectie, perceelnummer, appartementsrechtVolgnummer).
- `X-Api-Key` header auth, base URL `https://api.brk.kadaster.nl/esd-eto-apikey/bevragen/v2`
  (Kadaster's documented API-key test environment for BRK Bevragen v2 — production is
  `https://api.brk.kadaster.nl/esd/bevragen/v2`, unauthenticated for a different consumer class;
  procest targets the api-key-gated tier for both `test` and `live`, consistent with
  `integration.brk.baseUrl` being operator-overridable).
- **Privacy scoping (the one deliberate deviation from a literal 1:1 BAG mirror)**:
  `BrkResponseMapper` maps `zakelijkGerechtigdheid` to **reference-only** envelopes
  (`identificatie` + `aardZakelijkRecht`) — never inline natural-person data (name, BSN, address).
  Procest's VTH/tax workflows need to know THAT a parcel has a registered title holder and what
  KIND of right it is (eigendom, erfpacht, opstal, …), not the holder's personal data; a caller
  that needs the full rightholder record must resolve BRK's own `zakelijkGerechtigden`
  sub-resource directly (out of scope here, and arguably should stay out of scope given BRK's own
  privacy-graduated access model for natural-person title data). This scoping is enforced at the
  mapper level (`BrkResponseMapperTest::testZakelijkGerechtigdenNeverLeaksPersonalDataFields`), not
  left to controller/consumer discipline.
- Object-lookup `geo` extraction reads `centroideLL`/`centroide_ll` (WGS84 point Kadaster's
  cadastral-map data conventionally exposes alongside the RD/EPSG:28992 `geometrie` polygon) —
  mirrors BAG's "punt vs vlak" precedent (a percel's polygon geometry is not exposed as a point;
  only the centroid is).

## Decision 2 — WOZ adapter targets Kadaster's WOZ Bevragen API, never the waardeloket

As corrected above, `WozApiAdapter` targets the Kadaster Haal Centraal **WOZ Bevragen** API
(LV-WOZ), the only structured, programmatic WOZ channel:

- `X-Api-Key` header auth (same convention as BAG/BRK), `test`/`live` tiers via `IntegrationMode`.
- **Known trade-off, documented rather than hidden** (mirrors how `bag-register-adapter/design.md`
  documented BAG's own key-registration friction): production WOZ Bevragen access additionally
  requires an **OIN + PKIOverheid certificate** issued to a registered WOZ data holder — heavier
  than BAG's self-service acceptatie key or KvK's shared public test key. `IntegrationMode` still
  defaults `integration.woz.mode` to `log` (fail-closed), so this has zero correctness impact on a
  fresh install; it only means going from `log` to `live` is an organisational onboarding step
  (documented in `LogWozAdapter`'s deferred-result `note`), not a code change.
- `DEFAULT_BASE_URL` is the SwaggerHub auto-mock server generated from the published WOZ Bevragen
  OpenAPI spec (`test`-tier smoke-testing only — explicitly labelled as such in the adapter's
  class docblock, never presented as a real Kadaster environment). Operators override
  `integration.woz.baseUrl` once Kadaster issues real acceptatie/live credentials.
- The frontend shim (`wozApi.js`) and its vitest suite explicitly assert no request ever targets
  `wozwaardeloket.nl` — codifying "this is not a valid binding target" as a regression test, not
  just documentation.

## Decision 3 — WOZ adapter does not duplicate BAG's address-resolution responsibility

The WOZ Bevragen API itself accepts postcode+huisnummer directly, so `WozAdapterInterface`
DOES offer `lookupAddress(postcode, huisnummer, huisletter?, toevoeging?, context)` — reusing the
same Dutch-postcode validation regex `BagApiAdapter` uses (each adapter keeps its own copy, no
cross-adapter coupling, consistent with how `BrkApiAdapter` also has its own independent
validation constants — no adapter depends on another adapter's internals). But this is a thin
pass-through to the WOZ API's own query parameters, NOT a reimplementation of BAG's address
search/normalization pipeline — `WozResponseMapper` never touches BAG's `BagResponseMapper` and
carries zero BAG-specific logic.

The **preferred** composition path is `lookupByNummeraanduiding(nummeraanduidingId, context)` — a
caller that already resolved an address via `BagApiAdapter::lookupAddress()` (or via a case's
`location.nummeraanduidingId` field — the pre-existing, not-yet-wired hook documented in
`bag-register-adapter/design.md` Decision 3) should pass that id straight through instead of
re-searching by postcode. This is documented explicitly in `WozAdapterInterface`'s docblock so
future callers default to the composition path rather than the duplicate-search path.

`lookupByWozObjectNummer(wozobjectnummer, context)` is the third, resource-shaped lookup (true
HTTP 404 on no match, mirrors BAG's `lookupObject` / BRK's `lookupObject`) for a caller that
already holds a WOZ object number (e.g. from a prior search's `extras.matches`, or from municipal
tax records).

## Decision 4 — Controllers mirror `BagController` exactly; no new UI panel

`BrkController` / `WozController` (`#[NoAdminRequired]`-equivalent docblock tag + `IUserSession`
null-check → 401, same as `BagController`):

- `GET /api/external/brk/parcel?kadastraleGemeenteCode=&sectie=&perceelnummer=[&appartementsrechtVolgnummer=]`
  → `brk#parcel`; `GET /api/external/brk/parcel/{id}` → `brk#object`.
- `GET /api/external/woz/value?nummeraanduidingId=` OR
  `?postcode=&huisnummer=[&huisletter=][&huisnummertoevoeging=]` → `woz#value` (the controller
  branches on which shape was supplied — `nummeraanduidingId` takes precedence, per Decision 3);
  `GET /api/external/woz/value/{wozobjectnummer}` → `woz#object`.

Response shape: the adapter never throws for "not configured" or "not found" — those are
`lookupStatus` values in a 200 JSON body (`{lookupStatus, parcel|wozObject, dormant, extras}`),
exactly mirroring `BagController`. HTTP error statuses are reserved for request-shape problems
(400) and unauthenticated access (401), plus a last-resort 500 net for a `Throwable` the adapter
itself should never produce.

No new case-detail UI panel ships here — mirrors the BAG/BRP/KvK precedent exactly (none of those
adapters have a case-detail consumer either, despite BRP/KvK being the oldest seams in the app).
`src/services/brkApi.js` / `src/services/wozApi.js` are thin fetch shims over the new routes,
ready for a future case-detail change to wire in.

## Decision 5 — Test strategy mirrors `BagAdapterTest` / `BagContractTest` / `BagControllerTest`

- `Brk|WozAdapterTest` (unit): request building (URL, headers — `X-Api-Key`), input-validation
  matrices, dormant/log-adapter behaviour (all 2-3 lookup methods per adapter), tier resolution
  via `IntegrationMode`, 404-vs-5xx-vs-network-failure error mapping.
- `Brk|WozResponseMapperTest` (unit): normalization matrix — full record, partial record (missing
  numeric/geo/valuation-history fields), single-string-vs-array coercion, multi-result mapping;
  WOZ additionally covers most-recent-valuation selection from `vastgesteldeWaarden[]`; BRK
  additionally asserts `zakelijkGerechtigden` never leaks a `naam`/`bsn` field even when present
  in the raw fragment (privacy-scoping regression test, Decision 1).
- `Brk|WozContractTest` (offline contract lane, mirrors `BagContractTest`): feeds each adapter a
  recorded fixture (`tests/fixtures/contracts/{brk,woz}/`) built from the published OpenAPI
  schemas/examples — no network access required, runs in PR CI.
- `Brk|WozControllerTest` (unit, mirrors `BagControllerTest`): 400 on missing params, 401 on no
  session, graceful 200 passthrough of `LOOKUP_DEFERRED` when dormant; `WozControllerTest`
  additionally asserts the `nummeraanduidingId`-vs-`postcode` routing branch (Decision 3).
- `tests/vitest/{brkApi,wozApi}.spec.js` (mirrors `bagApi.spec.js`): endpoint routing, optional-
  param forwarding, dormant passthrough; `wozApi.spec.js` additionally asserts no request ever
  targets `wozwaardeloket.nl` (Decision 2 regression test).

## Sources consulted

- BRK Bevragen: `https://kadaster.github.io/BRK-bevragen/`,
  `https://github.com/kadaster/BRK-bevragen`,
  `https://www.kadaster.nl/zakelijk/producten/eigendom/brk-bevragen`.
- WOZ-waardeloket (non-API): `https://data.overheid.nl/community/datarequest/api-voor-woz-waarde-woningen-o-b-v-adres`,
  `https://data.overheid.nl/community/application/woz-waardeloket`.
- WOZ Bevragen: `https://kadaster.github.io/WOZ-bevragen/getting-started`,
  `https://github.com/kadaster/WOZ-bevragen`,
  `https://www.kadaster.nl/zakelijk/producten/adressen-en-gebouwen/woz-api-bevragen`,
  `https://www.kadaster.nl/-/wie-mag-gebruikmaken-van-de-woz-api-huidige-bevragingen-`.
