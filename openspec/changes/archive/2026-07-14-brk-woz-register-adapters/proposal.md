# Proposal: brk-woz-register-adapters

## Why

Dutch base-registration integration (BRP, BAG, BRK, HR, WOZ) is mandatory table stakes for a
Dutch case-management product. Procest ships authoritative BRP (`HaalCentraalBrpAdapter`), KvK
(`KvkApiAdapter`) and BAG (`BagApiAdapter`) lookup seams, but no authoritative BRK (Basisregistratie
Kadaster — parcels/ownership references) or WOZ (Waardering Onroerende Zaken — property valuation)
seam. VTH (Vergunningen, Toezicht en Handhaving) and tax/spatial cases routinely need to resolve a
parcel's kadastrale aanduiding/oppervlakte or a property's WOZ-waarde against the authoritative
register before an assessment or enforcement decision. This change adds both remaining seams,
mirroring the BAG adapter pattern exactly (config-tier selection, dormant-by-default,
contract-tested).

## What Changes

- **REQ-BRK-001**: Add a `BrkAdapterInterface` port (mirrors `BagAdapterInterface`) with two
  lookups: by kadastrale aanduiding (gemeentecode + sectie + perceelnummer, optional
  appartementsrecht volgnummer), and by BRK object id.
- **REQ-BRK-002**: Add `LogBrkAdapter` — dormant default, logs intent, returns `LOOKUP_DEFERRED`,
  never contacts Kadaster.
- **REQ-BRK-003**: Add `BrkApiAdapter` — live adapter against Kadaster's Haal Centraal BRK
  Bevragen API v2 (`test`/`live` tiers via `IntegrationMode`), all HTTP confined to the adapter.
- **REQ-BRK-004**: Add `BrkResponseMapper` — pure, unit-testable normalization of the Kadaster
  HAL+JSON payload into a stable Procest-internal DTO (kadastrale gemeente, sectie, perceelnummer,
  oppervlakte, soortCultuurBebouwd, zakelijk-gerechtigden REFERENCES only — no inline personal
  data, geo centroid when present).
- **REQ-BRK-005**: Add `BrkController` + routes exposing the two lookups over HTTP
  (`GET /api/external/brk/parcel`, `GET /api/external/brk/parcel/{id}`), same auth posture and
  graceful not-configured response shape as the BAG seam.
- **REQ-WOZ-001**: Add a `WozAdapterInterface` port with three lookups: by postcode + huisnummer,
  by BAG nummeraanduiding identificatie (the preferred path — avoids re-implementing BAG's address
  search), and by wozobjectnummer.
- **REQ-WOZ-002**: Add `LogWozAdapter` — dormant default, mirrors `LogBagAdapter`/`LogBrkAdapter`.
- **REQ-WOZ-003**: Add `WozApiAdapter` — live adapter against Kadaster's Haal Centraal WOZ
  Bevragen API (`test`/`live` tiers), targeting the ONLY programmatic WOZ channel — the public
  `WOZ-waardeloket` (wozwaardeloket.nl) is a web-only individual-consultation viewer with NO
  programmatic API (confirmed during research; see design.md Decision 2 for the full correction of
  the build brief's "largely open" assumption).
- **REQ-WOZ-004**: Add `WozResponseMapper` — pure normalization, selecting the MOST RECENT
  valuation from a WOZ object's `vastgesteldeWaarden[]` history (wozobjectnummer, waarde,
  waardepeildatum, grondoppervlakte, gebruiksdoel, nummeraanduidingId).
- **REQ-WOZ-005**: Add `WozController` + routes (`GET /api/external/woz/value`,
  `GET /api/external/woz/value/{wozobjectnummer}`), same auth posture and graceful
  not-configured response shape as the BAG/BRK seams.
- **REQ-BRK-006 / REQ-WOZ-006**: Add `src/services/brkApi.js` / `src/services/wozApi.js` frontend
  shims over the new routes. No case-detail UI panel ships in this change — mirrors the
  BRP/KvK/BAG precedent (no existing base-registration adapter has a UI consumer either).

## Capabilities

### New Capabilities

- `brk-woz-register-adapters`: authoritative BRK parcel/ownership-reference lookup seam AND
  authoritative WOZ property-valuation lookup seam (config-tier adapters, HTTP controllers,
  contract-tested normalization).

## Standards

- **Kadaster Haal Centraal BRK Bevragen API v2**: `kadastraalonroerendezaken` resource
  conventions; `X-Api-Key` header auth.
- **Kadaster Haal Centraal WOZ Bevragen API (LV-WOZ)**: `wozobjecten` resource conventions;
  `X-Api-Key` header auth; restricted to WOZ data holders (municipalities) — matches Procest's
  actual customer base.
- **Haal Centraal conventions**: base URL + API key config surface, mirrored from the
  BRP/KvK/BAG adapters already in Procest.

## Impact

- **Backend**: new `lib/Service/External/Brk/` and `lib/Service/External/Woz/` namespaces
  (interface, result DTO, mapper, live + log adapters each); new
  `lib/Controller/BrkController.php` / `lib/Controller/WozController.php`; new routes in
  `appinfo/routes.php`; new DI bindings in `lib/AppInfo/Application.php` (mirror the existing
  BAG/KvK/BRP `registerService` factory closures).
- **Frontend**: new `src/services/brkApi.js` / `src/services/wozApi.js` (no Vue component
  changes — no existing case-detail panel consumes BRP/KvK/BAG either).
- **Dependencies**: none added. No OpenConnector coupling (mirrors BAG's direct-`IClientService`
  precedent).
