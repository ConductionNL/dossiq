---
status: done
status-note: >-
  2026-06 (migrate-cases-on-map-to-maps-overview-leaf, ADR-022, procest#112,
  commit bac6f7073): the bespoke in-app geo stack is RETIRED. Removed:
  GeoService, WfsService, WfsExportService, WmsWfsService, LocationService,
  CaseGeoController and the /api/cases/geo and /wfs/cases endpoints, the
  Leaflet components under src/components/map, caseGeoService,
  coordinateService, gisProxyService and the gis store. The cases-on-map
  surface now consumes OpenRegister's maps-overview endpoints through
  src/services/casesOnMapApi.js and renders them with the shared CnMapWidget,
  which owns clustering and the PDOK base layer. What remains in this app is
  the marker shaping in src/services/mapFormatters.js, covered by
  tests/vitest/casesOnMap.spec.js. This spec is retained for history; the geo
  capability is owned by OpenRegister.
---

# gis-integration Specification

## Purpose

GIS / geo capability for cases: a backend `GeoService` (RFC 7946 GeoJSON
validation + JSON-encoded (de)serialisation + clustered case-geo assembly), an
OGC WFS 2.0.0 endpoint (`/wfs/cases`, reusing `WfsExportService`), a clustered
cases-on-map data endpoint (`/api/cases/geo`, with a per-object access guard),
and a Leaflet-based frontend (read-only `GeoViewer` single-case map + full-screen
`CasesOnMapView` dashboard with filters + GeoJSON export). PDOK base layers /
geocoding are reached via OpenConnector and degrade gracefully when
OpenConnector or PDOK is unavailable — the map never hard-fails.

**Standards**: GeoJSON (RFC 7946), OGC WFS 2.0.0, GML 3.2, EPSG:4326, PDOK.
**Feature tier**: V1.

## Status

`partial` — the core backend (GeoService, WfsService, WfsController,
CaseGeoController) and the core frontend (GeoViewer, CasesOnMapView + pure
data-shaping helpers) are built, tested (PHPUnit + vitest + Newman) and gate-clean.
Honest residue (cross-app / live-only): live PDOK address & parcel autocomplete
in the LocationPicker rides on the OpenConnector PDOK adapter; WFS-endpoint CORS
verification and map-performance testing with 1000+ markers require a live
deployment + external tiles; a date-range filter on the cases-on-map view is a
UX refinement not yet built.

## Requirements
### Requirement: GeoService MUST validate and (de)serialise GeoJSON geometry

The system SHALL provide a backend `GeoService` that validates GeoJSON geometry
per RFC 7946 and normalises the JSON-encoded stored representation into a
canonical Feature on read.

#### Scenario: Validate supported geometry types

@e2e exclude the geometry validation this describes is no longer in this app.
bac6f7073 (issue #112, ADR-022) removed the bespoke geo stack when cases-on-map
moved onto OpenRegister's maps-overview surface, and the PHPUnit class this
reason used to name was deleted with the code. Retained for history.

- **GIVEN** a Point, Polygon or MultiPolygon GeoJSON geometry
- **WHEN** it is validated by `GeoService::validateGeometry()`
- **THEN** a structurally-valid geometry within the WGS84 envelope MUST return no errors
- **AND** an unsupported type, malformed coordinates, or out-of-range coordinates MUST return a stable error code

#### Scenario: Normalise JSON-encoded stored geometry

@e2e exclude the stored-geometry normalisation this describes was removed by
bac6f7073 with the rest of the bespoke geo stack. OpenRegister now owns the
canonical representation, and the PHPUnit class this reason used to name was
deleted with the code.

- **GIVEN** a geometry stored JSON-encoded on a case location
- **WHEN** it is read via `GeoService::normaliseGeometry()`
- **THEN** the value MUST be decoded (string or array) into a canonical GeoJSON Feature
- **AND** an empty or invalid value MUST resolve to null so the caller can fall back

### Requirement: Cases-on-map endpoint MUST be clustered and access-guarded

The system SHALL expose `GET /api/cases/geo` returning a clustered, filtered
GeoJSON FeatureCollection of case locations, restricted to the cases the
requesting user may read (no IDOR).

#### Scenario: Clustered, filtered case locations

@e2e exclude GET /api/cases/geo no longer exists here. bac6f7073 replaced it
with a call to OpenRegister's maps-overview points endpoint, which does the
clustering and the filtering. Both PHPUnit classes this reason used to name
were deleted with the endpoint, and this app runs no Newman suite.

- **GIVEN** cases with locations across the Netherlands
- **WHEN** the client requests `/api/cases/geo` with a zoom level and optional zaaktype/status/bounds filters
- **THEN** nearby points MUST be grid-clustered below the cluster-disable zoom
- **AND** individual points MUST be returned at high zoom
- **AND** a `total` and `filtered` count MUST be included

#### Scenario: Per-object access guard excludes inaccessible cases

@e2e exclude the per-object access guard moved with the endpoint. OpenRegister's
maps-overview points endpoint returns markers already scoped by RBAC, so the
guard is enforced there and not here. The PHPUnit class this reason used to
name was deleted by bac6f7073.

- **GIVEN** two located cases where the user may read only one
- **WHEN** the user requests `/api/cases/geo`
- **THEN** only the readable case's location MUST appear in the FeatureCollection

### Requirement: Public OGC WFS 2.0.0 endpoint MUST expose case locations

The system SHALL expose `GET /wfs/cases` implementing OGC WFS 2.0.0
(GetCapabilities, DescribeFeatureType, GetFeature) so external GIS clients can
consume case locations as a standard feature layer. It MUST reuse the existing
`WfsExportService` for data and degrade to an empty (but valid) FeatureCollection
when OpenRegister is unavailable.

#### Scenario: GetCapabilities advertises the case feature type

@e2e exclude the WFS endpoint was removed by bac6f7073. This app no longer
serves /wfs/cases, the PHPUnit class this reason used to name was deleted with
it, and this app runs no Newman suite. Retained for history.

- **GIVEN** an authenticated WFS client
- **WHEN** it requests `GET /wfs/cases?service=WFS&request=GetCapabilities`
- **THEN** a well-formed WFS 2.0.0 capabilities document MUST advertise the `dossiq:cases` feature type and the supported operations

#### Scenario: GetFeature returns GML members honouring BBOX

@e2e exclude GetFeature went with the rest of the WFS endpoint in bac6f7073.
The PHPUnit class this reason used to name was deleted with it, and this app
runs no Newman suite.

- **GIVEN** located cases
- **WHEN** a WFS client requests `GetFeature` with an optional BBOX
- **THEN** a GML 3.2 `wfs:FeatureCollection` MUST be returned with one member per matching case location
- **AND** the endpoint MUST be gated by the `geo_wfs_endpoint_enabled` setting

### Requirement: GeoViewer MUST render a read-only single-case map

The system SHALL provide a `GeoViewer` frontend component that renders a
read-only map of a single case location, delegating map rendering to the
existing Leaflet-based `CaseMap` and showing an empty-state when no geometry
is set.

#### Scenario: Empty-state when a case has no location

@e2e exclude Component-level empty-state; GeoViewer is a thin wrapper over CaseMap and map-tile rendering needs network.

- **GIVEN** a case without geometry
- **WHEN** the GeoViewer is shown
- **THEN** an empty-state MUST be displayed instead of a blank map

### Requirement: CasesOnMapView MUST provide a full-screen map dashboard

The system SHALL provide a `CasesOnMapView` full-screen view that loads the
`/api/cases/geo` FeatureCollection, shapes it via pure helpers, supports
zaaktype/status filtering, navigates to case detail on marker click, and
exports the visible single cases as GeoJSON. It MUST degrade gracefully when
the backend or PDOK tiles are unavailable.

#### Scenario: Cases-on-map view renders the map dashboard

- **GIVEN** the user opens the cases-on-map view
- **WHEN** the view loads
- **THEN** the map dashboard MUST render with its filter sidebar and a count summary
- **AND** a backend/data failure MUST surface a non-blocking notice rather than a broken page

#### Scenario: Map data shaping is pure and testable

@e2e exclude pure data-shaping helpers, covered by tests/vitest/casesOnMap.spec.js.
After bac6f7073 the shaping is shapeMarkerFeatures in
src/services/mapFormatters.js, and that vitest spec asserts the coordinate
order, the status colour and icon, malformed rows and non-array input.

- **GIVEN** a `/api/cases/geo` FeatureCollection
- **WHEN** it is shaped for the map
- **THEN** cluster features MUST keep their member count and single cases MUST carry a status colour
- **AND** the GeoJSON export MUST exclude cluster aggregates

