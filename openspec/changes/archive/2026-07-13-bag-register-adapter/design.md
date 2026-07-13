# Design: bag-register-adapter

> Verified against procest HEAD (worktree `bag-adapter`, branch `wip/bag-register-adapter`,
> merged to `origin/development` @ `624c5d1`) on 2026-07-13.

## Context corrections against HEAD

The build brief assumed procest has no BAG data access at all, and that BRP/KvK expose HTTP
controllers to mirror. Neither is exactly true at HEAD:

| Assumption | Reality at HEAD | Consequence |
|---|---|---|
| No existing BAG access | `lib/Service/Pdok/PdokBagService.php` already does id-based BAG WFS lookups (`getNummeraanduiding`, `getVerblijfsobject`, `getPand`) against `service.pdok.nl/lv/bag/wfs/v2_0`, cached 24h, normalized (camelCase, `bouwjaar`/`oppervlakte` as int, `gebruiksdoel` as array). It is currently **unused** (no caller in `lib/` or `src/`). | See "PDOK-overlap analysis" below — the new adapter must justify its existence rather than duplicate this. |
| BRP/KvK expose HTTP lookup controllers to mirror | `HaalCentraalBrpAdapter` / `KvkApiAdapter` are consumed **internally only** (`ConflictOfInterestService`, `Beschikking/LibresignApiClient` reference the interfaces via `Application.php`'s DI factory closures) — grep confirms zero `lib/Controller/*` files reference either interface, and `appinfo/routes.php` has no BRP/KvK route. PDOK itself is proxied through **openconnector**, not a procest controller (`src/services/pdokService.js` calls `/apps/openconnector/api/pdok/*` directly; routes.php explicitly documents "PDOK address resolution is owned separately by the migrate-pdok-to-openconnector change"). | This change is the **first** procest HTTP controller for a base-registration lookup. `LhsController` (`GET /api/vth/lhs/lookup`) is used as the auth/response-shape sibling instead — same `#[NoAdminRequired]`-equivalent posture (docblock `@NoAdminRequired` + explicit `IUserSession` 401 guard), same `JSONResponse` error envelope, same 400/401/500 mapping. |
| Case schema has no location hook | `lib/Settings/procest_register.json`'s `location` schema (`Case Location`, 0..N per case) already has `source` enum `[bag, pdok-reverse, gps, free, geocoded, import]` and a `nummeraanduidingId` field ("MUST be present when source = bag") — but **nothing populates or validates it today**; no Vue component reads `nummeraanduidingId` or `source`. | Confirmed clean, pre-existing enrichment hook (see Impact/Case linkage below) — validating a `location.source=bag` row against this new adapter is real follow-up work, not invented scope. |

## PDOK-overlap analysis (why a second BAG seam is justified)

Procest already touches BAG data three ways:

1. `PdokLocatieserverService.suggest()` — free-text autocomplete (PDOK Locatieserver, not raw
   BAG). Out of scope here per the build brief — this change does not touch address suggest.
2. `PdokBagService.get{Nummeraanduiding,Verblijfsobject,Pand}(id)` — **id-based** BAG WFS reads
   against PDOK's open-data mirror (`service.pdok.nl/lv/bag/wfs/v2_0`). Free, keyless, no
   authoritative-source guarantee, currently unused.
3. Neither service supports a **postcode + huisnummer → address(es)** query — PDOK's BAG WFS
   endpoint only accepts an `identificatie` filter (see `PdokBagService::fetch()`), and
   Locatieserver's `suggest`/`free` are fuzzy free-text search, not a structured exact-match
   address query.

Decision: `BagApiAdapter` targets Kadaster's own **BAG API Individuele Bevragingen v2**
(`api.bag.kadaster.nl`), not PDOK, for two reasons:

- **The postcode+huisnummer gap is real.** No existing procest code can answer "give me the BAG
  address record(s) for 1234AB 10" with structured fields — this is the adapter's primary new
  capability (REQ-BAG-001/003/007).
- **Authoritative-source parity with BRP/KvK.** BRP uses Haal Centraal (not, say, a free CBS
  persons mirror); KvK uses the KvK Handelsregister API directly (not a free scrape). Kadaster's
  Individuele Bevragingen API is the same class of product: the legally authoritative,
  key-gated, individual-record channel Kadaster operates specifically for consumers who need
  provable per-lookup provenance (VTH enforcement decisions, legal notices) — as opposed to PDOK's
  bulk open-data WFS mirror, which lags the authoritative register and carries no such guarantee.
  Mirroring the BRP/KvK authoritative-vs-open-data split keeps procest's base-registration seams
  consistent.

For the **id-based** lookup (`verblijfsobject`/`pand`), this DOES overlap with the unused
`PdokBagService.getVerblijfsobject()`/`getPand()`. `BagApiAdapter::lookupObject()` is kept anyway,
scoped to the same authoritative-source justification (the Individuele Bevragingen response for a
pand/verblijfsobject-by-id carries `Accept-Crs`-negotiated authoritative geometry and status
fields the WFS mirror does not expose identically), and because splitting "by id" across two
different adapters (WFS for id, Kadaster API for address search) would be a worse seam than one
adapter with two methods sharing one config tier / one dormant-fallback contract. `PdokBagService`
is left untouched — it remains the free/open id-lookup path for map/GIS consumers that don't need
authoritative provenance. Neither service is deleted or deprecated by this change.

**Known trade-off, documented rather than hidden:** unlike KvK (which ships a public, shared,
zero-registration TEST api key), Kadaster's BAG API Individuele Bevragingen has no equivalent
published shared key — even the `acceptatie` (test) tier requires a free self-service key request
(`formulieren.kadaster.nl`). `IntegrationMode` still defaults every seam to `log` (fail-closed), so
this has no correctness impact — it only means the `test` tier contract lane below (mirroring
`BrpKvkContractTest`) runs against **recorded fixtures**, not a live network call, same as the BRP
mock/KvK test lanes already do in CI.

## Decision 1 — Adapter shape mirrors BRP/KvK exactly

`lib/Service/External/Bag/`:

- `BagAdapterInterface` — two methods (`lookupAddress(postcode, huisnummer, ..., context)`,
  `lookupObject(objectType, id, context)`), both returning `BagLookupResult`.
- `BagLookupResult` — `lookupStatus` (`FOUND | NOT_FOUND | INVALID_INPUT | LOOKUP_DEFERRED |
  LOOKUP_ERROR`), `address` (normalized envelope array, empty unless `FOUND`), `dormant` (bool),
  `extras` (array — `tier`, `count`/`matches` for multi-result address searches, `reason` on
  error).
- `LogBagAdapter` — dormant default; logs intent (postcode/huisnummer are not PII, logged as-is,
  same call as `LogKvkHandelsregisterAdapter`); returns `LOOKUP_DEFERRED`.
- `BagApiAdapter` — live adapter; validates Dutch postcode format before any HTTP call
  (`INVALID_INPUT`, no network call — cheaper and more precise than a 400 round-trip); delegates
  all normalization to `BagResponseMapper`; maps Kadaster HTTP 404 → `NOT_FOUND`, any other
  transport/HTTP failure → `LOOKUP_ERROR` (never throws into the caller, matching the BRP/KvK
  fail-soft contract).
- `BagResponseMapper` — pure static-shaped class (no I/O), takes a decoded Kadaster HAL+JSON
  fragment (`adresseerbaarObject`/`verblijfsobject`/`pand`), returns the normalized DTO array
  (`street`, `houseNumber`, `houseLetter`, `houseNumberAddition`, `postcode`, `city`,
  `gebruiksdoel` (always array), `oorspronkelijkBouwjaar` (int|null), `oppervlakte` (int|null),
  `geo` (`{lat, lng}` when the payload carries a point, converted from RD (EPSG:28992) via the
  documented Kadaster `Accept-Crs: epsg:4326` request header — the API reprojects server-side, no
  client-side RD→WGS84 math needed).

`Application::register()` gains a `BagAdapterInterface` factory closure identical in shape to the
existing KvK/BRP ones — `IntegrationMode::resolve('bag', [TEST, LIVE])`, binds `BagApiAdapter` when
non-`log`, else `LogBagAdapter`.

## Decision 2 — Controller mirrors `LhsController`, not a nonexistent BRP/KvK controller

`BagController` (`#[NoAdminRequired]`-equivalent docblock tag + `IUserSession` null-check → 401,
same as `LhsController::lookup()`):

- `GET /api/external/bag/address?postcode=&huisnummer=[&huisletter=][&toevoeging=]` →
  `bag#address`
- `GET /api/external/bag/pand/{id}` → `bag#pand`
- `GET /api/external/bag/verblijfsobject/{id}` → `bag#verblijfsobject`

Response shape: the adapter never throws for "not configured" or "not found" — those are
`lookupStatus` values in a 200 JSON body (`{lookupStatus, address, dormant, extras}`), exactly
mirroring how a dormant BRP/KvK adapter behaves for its internal callers. The controller reserves
HTTP error statuses for request-shape problems it can detect before calling the adapter (400 for
missing/invalid params — mirrors `LhsController`'s "caseId/ernst/gedrag/actorType zijn verplicht"
400 pattern) and true 500s (unexpected `Throwable`, logged, generic message — the adapter itself
should never throw, so this is a last-resort net).

## Decision 3 — Case linkage ships as endpoints only, no new UI panel

The `location` schema's `source: bag` + `nummeraanduidingId` fields are the intended hook, but:

- no existing Vue component reads either field (grep confirms zero hits in `src/`);
- BRP/KvK — the two adapters this change mirrors — have **no** case-detail UI panel either,
  despite being older and already live;
- the build brief explicitly rules out inventing a new case-detail panel.

This change therefore ships `src/services/bagApi.js` (thin fetch shim over the three routes,
mirrors `pdokService.js`'s no-t()-calls, `messageKey`-on-degraded style) and stops there. Wiring
"validate a `location` row's `nummeraanduidingId` against `BagApiAdapter::lookupObject()` before
save" into the case/location save path is filed as explicit follow-up in `tasks.md`, not built
here — consistent with how the BRP/KvK adapters themselves shipped ahead of any UI consumer.

## Decision 4 — Test strategy mirrors `IntegrationTierTest` / `BrpKvkContractTest`

- `BagAdapterTest` (unit): request building (URL, headers — `X-Api-Key`, `Accept-Crs`), postcode
  validation matrix, dormant/log-adapter behaviour, tier resolution via `IntegrationMode`.
- `BagResponseMapperTest` (unit): normalization matrix — full record, partial record (missing
  `oorspronkelijkBouwjaar`/`oppervlakte`/geo), empty/missing fields, multi-result address search.
- `BagContractTest` (offline contract lane, mirrors `BrpKvkContractTest`): feeds the adapter a
  recorded Kadaster BAG API Individuele Bevragingen v2 response fixture
  (`tests/fixtures/contracts/bag/`) and asserts the mapped DTO shape — no network, runs in PR CI.
- `BagControllerTest` (unit, mirrors the existing controller test style in this app — see
  `tests/Unit/Controller/`): 400 on missing params, 401 on no session, graceful 200 passthrough of
  `LOOKUP_DEFERRED` when dormant.
