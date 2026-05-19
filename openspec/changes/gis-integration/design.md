# GIS Integration — Design Document

**Change name:** gis-integration
**Issue:** #462
**Kind:** code
**Status:** in-progress

## Summary

Add geographic information system (GIS) capabilities to Procest: map-based views of cases, location tagging on cases via address lookup or map interaction, integration with Dutch geo-data services (PDOK, WMS/WFS), and WFS exposure of case locations for external GIS applications.

## Architecture

The GIS feature is built on four pillars:

1. **OpenRegister schemas** — `location`, `wmsLayer`, `mapLayer` objects stored in the Procest register. No direct DB writes; all persistence flows through `ObjectService`.
2. **Backend services** — `LocationService` (location domain), `WmsWfsService` (layer resolution + proxy), `GisProxyService` (outbound HTTP with allowlist + rate limiting), `WfsExportService` (GeoJSON/WFS output for external consumers), `PdokLocatieserverService` + `PdokBagService` (PDOK integration).
3. **Backend controllers** — `GisProxyController`, `WmsWfsController`, `WfsExportController`.
4. **Frontend components** — `MapComponent`, `CaseMap`, `LocationPicker`, `AddressSearch`, `MapLayerSwitcher`, plus views `LocationTab`, `MapLayerSettings`, `CaseMapWidget`, and the manifest-driven `CaseMap` overview page.

## Acceptance criteria mapping

| AC | Implementation |
|----|---------------|
| 1 | `LocationPicker.vue` + `AddressSearch.vue` with PDOK autocomplete |
| 2 | `LocationTab.vue` embedding `CaseMap.vue` in read-only mode |
| 3 | Auto-fit on `geometry` / `latitude+longitude` in `LocationTab` |
| 4 | `MapLayerSettings.vue` + `WmsWfsService` + manifest-driven WmsLayers admin page |
| 5 | Manifest `CaseMap` page (type: map) with status/caseType/assignee filters + clustering |
| 6 | `WfsExportController` + `WfsExportService` → GET `/api/gis/wfs` GeoJSON FeatureCollection |
| 7 | `LocationPicker` free-location mode (GPS coords, free-text, map pin) |
| 8 | `AddressSearch` PDOK Locatieserver suggest/lookup with real-time autocomplete |

## WFS export endpoint (AC 6)

**URL:** `GET /index.php/apps/procest/api/gis/wfs`

**Parameters:**
- `outputFormat` — `application/json` (GeoJSON, default) or `text/xml` (not yet supported)
- `typeName` — `procest:cases` (default, only value currently supported)
- `maxFeatures` — max features to return (default 500, max 2000)
- `bbox` — `minLon,minLat,maxLon,maxLat` in WGS84 (optional)
- `status` — filter by case status (optional)
- `caseType` — filter by case type name (optional)

**Output:** GeoJSON FeatureCollection where each Feature represents one case location. Feature properties include case metadata (id, identifier, title, status, caseType, assignee).

**Auth:** `#[NoAdminRequired]` — any authenticated Nextcloud user (external GIS apps authenticate via HTTP Basic Auth or OIDC).

## Validation rules (location payload)

- `source` MUST be one of: `bag`, `pdok-reverse`, `gps`, `free`, `geocoded`, `import`
- `case` UUID MUST be present
- `source=bag` → `nummeraanduidingId` required
- `source=pdok-reverse` → `latitude` + `longitude` required
- `source=gps` → `latitude`, `longitude`, `accuracyRadius` required
- `source=free` → at least `formattedAddress` OR `latitude`+`longitude`
- Every location MUST carry either `nummeraanduidingId` OR `latitude`+`longitude`

## Declarative-vs-imperative decision

The location lifecycle (attach, validate, list) does not fit any `x-openregister-*` extension because:
- Validation cross-checks multiple fields with source-dependent rules (no schema extension for this)
- PDOK reverse geocoding requires lazy DI to an optional external service
- WFS export requires joining location objects with case objects into a GeoJSON FeatureCollection

All three are therefore implemented as imperative service methods with appropriate unit tests.

## Security

- All outbound WMS/WFS proxy traffic flows through `GisProxyService` (allowlist + rate limit).
- `WfsExportController.getFeatures()` uses `#[NoAdminRequired]` — no admin escalation.
- No per-object IDOR concern: the WFS export lists all locations visible to the authenticated user; no mutation endpoints exist on this controller.
