# Proposal: migrate-cases-on-map-to-maps-overview-leaf

## Why

`migrate-maps-to-maps-leaf` moved the **per-case** map surface onto OpenRegister's
per-object `maps` integration leaf, but explicitly DEFERRED the **multi-object**
cases-on-map overview (`CasesOnMapView`, the `/map` page) and the bespoke
Leaflet + WMS/WFS stack that backs it. The reason for the deferral (recorded in
the `case-map-via-maps-leaf` spec NOTE and Codeberg procest issue #112) was that
OR's `MapsProvider` is a per-object surface — `list()` returns lat/lng rows for
one object — with no page-level multi-object render surface.

OpenRegister now ships that surface (openregister PR #154): a page-level
multi-object **maps-overview** render surface on the integration registry —
`MapsOverviewService::registerOverview()` declares a `map` page widget with a
declarative base-layer config (PDOK WMTS default), and
`GET /api/integrations/maps/overviews/{register}/{schema}/points` returns the
RBAC-scoped marker point set (`{ points: [{ id, label, lat, lng, register,
schema, geometry }], count }`). The points query runs OR's canonical read path
with `_rbac: true` for non-admins (fail-closed; an anonymous / low-privilege
caller only ever sees public-readable objects), and the register/schema scope
keys are caller-immutable. This closes the blocker.

Keeping procest's parallel multi-object Leaflet/WMS/WFS stack is a direct
**ADR-022** violation (Apps Consume OpenRegister Abstractions) and a needless
maintenance + RBAC surface (the bespoke `/api/cases/geo` did its own per-object
access guard — exactly the kind of bespoke RBAC ADR-005 wants delegated to OR).

## What

This change completes the maps-leaf migration — the removal `migrate-maps-to-maps-leaf`
scoped but deferred:

1. The `/map` page (`CasesOnMapView`) consumes OR's maps-overview surface: it
   declares the `cases-on-map` overview at mount (idempotent) and fetches its
   markers from the RBAC-scoped points endpoint. The markers render through the
   library's declarative `CnMapWidget` (which owns the Leaflet engine,
   clustering, and base-layer tiles) — procest embeds no Leaflet of its own.
2. The bespoke multi-object Leaflet stack is removed: `src/components/map/`
   (CaseMap, MapComponent, MapLayerSwitcher, MapLegend, SpatialFilter, CasePopup,
   GeoViewer), `CaseMapWidget`, `MapLayerSettings`, and the
   `caseGeoService` / `coordinateService` / `gisProxyService` / `gis` store.
3. The bespoke WMS/WFS + cases-geo backend is removed:
   `WmsWfsService` / `WfsService` / `WfsExportService` / `GeoService` /
   `LocationService` / `MapLayerService` / `GisProxyService` and their
   controllers + routes (`/api/cases/geo`, `/wfs/cases`, `/api/gis/*`,
   `/api/wms-wfs/proxy`, `/api/map-layers`).
4. The `case` geo *data* contract is unchanged — the geometry field stays in
   procest's register; only the multi-object *rendering + query* moves to OR.

The single-object create-dialog location picker (`LocationPicker` /
`AddressSearch`) stays in place — it is a per-case editing surface, not the
multi-object overview, and its address-resolution path is owned by the separate
`migrate-pdok-to-openconnector` change. PDOK base-tile / geocoding services
(`PdokService`, `Pdok/*`) are likewise out of scope here.

## Capabilities

### Modified Capabilities

- `case-map-overview` — the multi-object overview is rendered from OpenRegister's
  page-level maps-overview surface (RBAC-scoped points + declarative base layer)
  through the library's `CnMapWidget`, instead of a bespoke in-app
  Leaflet/WMS/WFS stack and a bespoke `/api/cases/geo` endpoint.
- `case-map-via-maps-leaf` — the deferral NOTE that blocked removal of the
  bespoke multi-object stack on issue #112 is resolved; the WMS/WFS service
  classes are removed (the per-case tab already delegated to the leaf).

## Affected Projects

- [x] Project: `procest` — all implementation tasks are in this repo
- [x] Project: `openregister` — no code change; the maps-overview surface is consumed, not modified

## Out of Scope

- The maps-overview surface's own implementation in OR (openregister PR #154).
- PDOK geocoding / address resolution — owned by `migrate-pdok-to-openconnector`.
- The single-object create-dialog `LocationPicker` / `AddressSearch` (per-case
  editing surface, not the multi-object overview).
- Backfill/migration of existing case geo data (the field shape is unchanged).

## Success Criteria

- `openspec validate migrate-cases-on-map-to-maps-overview-leaf --strict` exits 0.
- The `/map` page plots all RBAC-visible cases from OR's points endpoint.
- The bespoke `src/components/map/*` Leaflet stack, the WMS/WFS + cases-geo
  services/controllers/routes, and the `/api/cases/geo` endpoint are removed.
- Codeberg procest issue #112 is closed.
