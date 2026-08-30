# Proposal: bag-register-adapter

## Why

Dutch base-registration integration (BRP, BAG, BRK, HR, WOZ) is mandatory table stakes for a
Dutch case-management product. Procest ships authoritative BRP (`HaalCentraalBrpAdapter`) and
KvK (`KvkApiAdapter`) lookup seams, but no authoritative BAG (Basisregistratie Adressen en
Gebouwen) seam. VTH (Vergunningen, Toezicht en Handhaving) and other spatial cases routinely need
to resolve an address or pand/verblijfsobject against the authoritative register — e.g. to confirm
a `gebruiksdoel` or `oorspronkelijkBouwjaar` before an enforcement decision.

Procest already has PDOK-backed BAG access (`PdokBagService`, `PdokLocatieserverService`), but
PDOK's BAG WFS is an **open-data mirror**, not the authoritative individual-bevragingen channel
Kadaster operates for legally-relevant lookups — the same authoritative-vs-open-data distinction
BRP/KvK already draw. This change adds that seam, mirroring the BRP/KvK adapter pattern exactly
(config-tier selection, dormant-by-default, contract-tested). See `design.md` for the full
PDOK-overlap analysis.

## What Changes

- **REQ-BAG-001**: Add a `BagAdapterInterface` port (mirrors `BrpHaalCentraalAdapterInterface` /
  `KvkHandelsregisterAdapterInterface`) with two lookups: by postcode + huisnummer (`adressen`),
  and by BAG object id (`verblijfsobject` | `pand`).
- **REQ-BAG-002**: Add `LogBagAdapter` — dormant default, logs intent, returns
  `LOOKUP_DEFERRED`, never contacts Kadaster.
- **REQ-BAG-003**: Add `BagApiAdapter` — live adapter against Kadaster's BAG API Individuele
  Bevragingen v2 (`test`/`live` tiers via `IntegrationMode`), all HTTP confined to the adapter.
- **REQ-BAG-004**: Add `BagResponseMapper` — pure, unit-testable normalization of the Kadaster
  HAL+JSON payload into a stable Procest-internal DTO (street, number, postcode, city,
  gebruiksdoel, oorspronkelijkBouwjaar, oppervlakte, geo point when present).
- **REQ-BAG-005**: Add `BagController` + routes exposing the two lookups over HTTP
  (`GET /api/external/bag/address`, `GET /api/external/bag/pand/{id}`,
  `GET /api/external/bag/verblijfsobject/{id}`), same auth posture and graceful
  not-configured response shape as the internal BRP/KvK seams' own dormant-adapter contract.
- **REQ-BAG-006**: Add `src/services/bagApi.js` frontend shim over the new routes. No case-detail
  UI panel ships in this change (see Impact) — the `location` schema's existing `source: bag` /
  `nummeraanduidingId` fields are the documented, not-yet-wired enrichment hook.
- **REQ-BAG-007**: Dutch postcode format validated before any outbound call (`^[1-9][0-9]{3}[A-Z]{2}$`).

## Capabilities

### New Capabilities

- `bag-register-adapter`: authoritative BAG address + pand/verblijfsobject lookup seam
  (config-tier adapter, HTTP controller, contract-tested normalization).

## Standards

- **Kadaster BAG API Individuele Bevragingen v2**: `adressen`, `verblijfsobjecten/{id}`,
  `panden/{id}` resource conventions; `X-Api-Key` header auth; `Accept-Crs: epsg:28992`.
- **Haal Centraal conventions**: base URL + API key config surface, mirrored from the BRP/KvK
  adapters already in Procest.

## Impact

- **Backend**: new `lib/Service/External/Bag/` namespace (interface, result DTO, mapper, live +
  log adapters); new `lib/Controller/BagController.php`; new routes in `appinfo/routes.php`; new
  DI binding in `lib/AppInfo/Application.php` (mirrors the existing KvK/BRP `registerService`
  factory closures).
- **Frontend**: new `src/services/bagApi.js` (no Vue component changes — no existing case-detail
  panel consumes BRP/KvK either; the `location` schema's `source: bag` field is the wiring point
  for a future change, documented in `design.md`).
- **Dependencies**: none added. No OpenConnector coupling (BRP/KvK adapters call Kadaster/KvK
  directly via `IClientService`; this adapter does the same for symmetry — see `design.md` for the
  PDOK-overlap analysis).
