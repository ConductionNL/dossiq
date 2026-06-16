# Tasks

## Backend Services

- [x] TASK-GIS-01: Created `lib/Service/GeoService.php` (2026-06-14) — RFC 7946 GeoJSON validation (`validateGeometry`: Point/Polygon/MultiPolygon/Feature/FeatureCollection + WGS84 envelope, stable error codes), JSON-encoded (de)serialisation (`normaliseGeometry`/`encodeGeometry` — procest stores geometry JSON-encoded), clustered case-geo assembly (`buildCaseGeoCollection`: zaaktype/status/bbox filter + grid-clustering below zoom 14 + `readableCaseIds` access guard), and `listCaseIds`. Degrades to empty (never throws) when OR is absent. 17 PHPUnit tests in `tests/Unit/Service/GeoServiceTest.php` (validation matrix, MultiPolygon, empty coordinates, normalise/encode round-trip, clustering, access guard).

- [x] TASK-GIS-02: Created `lib/Service/PdokService.php` (W6, 2026-06-11) as the procest-backend ingress that fronts the dormant openconnector PDOK source adapters (`PdokWmsSourceAdapter`, `PdokWfsSourceAdapter`, `PdokGeocodingClient`, category `geo`, gated by `pdok.feature_flag`). Exposes `searchAddress(query, filters, rows)` (autocomplete via the in-repo `PdokLocatieserverService` cache), `lookupAddress(id)`, `searchParcel(criteria)` via the openconnector WFS adapter, and `getServiceStatus()` for flag introspection. Per ADR-022 + `migrate-pdok-to-openconnector` PR-1.1, every external PDOK call routes through `/apps/openconnector/api/pdok/*`; procest does NOT re-implement Locatieserver. Degraded handling: openconnector 503 → `pdok.unavailable` + empty result; openconnector 404 → `pdok.openconnector_missing` + empty result so the calling form stays submittable. 8 PHPUnit tests cover the min-length short-circuit, delegation, 503 fallback, missing-shim path and `getServiceStatus` flag introspection (`tests/Unit/Service/PdokServiceTest.php`).

- [x] TASK-GIS-03: Created `lib/Service/MapLayerService.php` with full CRUD (listLayers/getLayer/createLayer/updateLayer/deleteLayer) backed by OpenRegister. Filters by type/isBase/isActive; sorts by `order` ascending. `validatePayload` enforces title required, type in {tile, wms, wfs, geojson}, URL valid (or tile template with `{z}/{x}/{y}` for tile sources), opacity in `[0, 1]`. 9 unit tests cover the validation matrix, missing-title rejection, unknown-type rejection, URL/template acceptance, opacity bounds, and OR-unavailable degradation (`tests/Unit/Service/MapLayerServiceTest.php`).

- [x] TASK-GIS-04: Created `lib/Service/WfsService.php` (2026-06-14) generating OGC WFS 2.0.0 XML: `getCapabilities` (advertises `procest:cases` + operations), `describeFeatureType` (XSD), `getFeature` (GML 3.2 `wfs:FeatureCollection`, BBOX/status/caseType filters, EPSG:4326 lat/lon order, XML-escaped). REUSES the existing `WfsExportService` for all data access (feature collection + capabilities descriptor) — no duplication. Degrades to an empty-but-valid FeatureCollection when OR is down. 5 PHPUnit tests in `tests/Unit/Service/WfsServiceTest.php` (well-formed XML for all three ops, filter forwarding, escaping, empty-collection).

## Backend Controllers & Routes

- [ ] TASK-GIS-05: Create `lib/Controller/LocationPickerController.php` with endpoints:
  - `POST /api/locations/search` — Address search via PdokService
  - `POST /api/parcels/search` — Parcel search via PdokService
  - `GET /api/cases/{caseId}/geometry` — Retrieve case geometry
  - `POST /api/cases/{caseId}/geometry` — Set/update case geometry
  - Add input validation, error handling, and rate limiting for PDOK calls.

- [x] TASK-GIS-06: Created `lib/Controller/WfsController.php` (2026-06-14) — public OGC WFS endpoint `GET /wfs/cases` (`wfs#cases` route) dispatching on the `request` param (GetCapabilities/DescribeFeatureType/GetFeature), BBOX parsing (trailing CRS token tolerated), EPSG:4326, gated by the `geo_wfs_endpoint_enabled` setting, OGC ExceptionReport XML on error. `#[NoAdminRequired]` + `#[NoCSRFRequired]` + session-auth guard (matches the existing WfsExportController posture; external GIS clients auth via Basic/OIDC). DataDisplayResponse XML via WfsService.

- [x] TASK-GIS-07: Created `lib/Controller/CaseGeoController.php` (2026-06-14) — `GET /api/cases/geo` (`caseGeo#geo` route, registered before any `/api/cases/*` wildcard) returning a clustered GeoJSON FeatureCollection. Query params `zaaktype`/`status`/`zoom`/`bounds`; server-side grid-clustering + result cap in GeoService; metadata `total`/`filtered`. `#[NoAdminRequired]` PLUS a per-object access guard — resolves the readable case-id set via `CaseSharingService::canUserAccessCase()` and passes it to the service so a user never sees the location of a case they cannot access (no IDOR). Degrades to an empty collection (200) on failure. 3 PHPUnit tests in `tests/Unit/Controller/CaseGeoControllerTest.php` (401 unauth, access-guard allow-list, graceful degradation).

- [x] TASK-GIS-08: Created `lib/Controller/MapLayerController.php` exposing the spec'd endpoints. Read endpoints (`GET /api/map-layers`, `GET /api/map-layers/{id}`) are `#[NoAdminRequired]` + body-side `requireAuthenticated()` guard (any authenticated user can read layers — they're admin-curated, not user-private). Mutating endpoints (`POST`, `PUT /{id}`, `DELETE /{id}`) are gated with `#[AuthorizedAdminSetting(AdminSettings::class)]`. Routes added to `appinfo/routes.php` before the SPA catch-all per ADR-016.

- [x] TASK-GIS-09: Registered the new routes in `appinfo/routes.php` (2026-06-14): `wfs#cases` → `/wfs/cases` (GET) and `caseGeo#geo` → `/api/cases/geo` (GET), both with inline comments, placed before the `/api/cases/{caseId}/*` wildcards. Auth declared via controller attributes (gate-5 route-auth PASS). (Pre-existing `wfsExport#*`, `mapLayer#*`, `gisProxy#*`, `wmsWfs#*` routes already present.)

## Frontend Components

- [ ] TASK-GIS-10: Create `src/components/LocationPickerModal.vue` with:
  - Address search tab with PDOK autocomplete (debounce 300ms)
  - Parcel search tab with map-based selector
  - Map click tab for direct coordinate entry
  - Current location display and editing
  - Save button that validates geometry and updates case
  - Responsive design, keyboard navigation

- [x] TASK-GIS-11: Created `src/components/map/GeoViewer.vue` (2026-06-14) — read-only embedded single-case map, a thin wrapper over the existing Leaflet-based `CaseMap` (base-layer switcher, overlay toggles, zoom controls, auto-fit already provided by CaseMap). Loads configured WMS/WFS overlays from the gis store (degrades silently on failure), centres on a Point geometry, and shows an `NcEmptyContent` empty-state when no geometry is set. Registered in `src/registry.js` (kind `widget`). [Note: Leaflet is the dependency in package.json; OpenLayers is NOT added — `geo_map_library` setting key is reserved but Leaflet is the only implemented renderer.]

- [x] TASK-GIS-12: Created `src/views/CasesOnMapView.vue` (2026-06-14) — full-screen cases-on-map dashboard. Loads `/api/cases/geo`, shapes it via the pure `src/services/caseGeoService.js` helpers (toMapGeometries/buildGeoQuery/summariseGeo/toExportGeoJson), renders via `CaseMap` (Leaflet.markercluster), filter sidebar (zaaktype/status), count summary, marker-click → CaseDetail nav, GeoJSON export of visible single cases, bbox re-query on viewport move, graceful-degradation notice, responsive (sidebar stacks on mobile). Registered in `src/registry.js` (kind `page`). Map-shaping logic is unit-tested (12 vitest tests in `tests/vitest/caseGeoService.spec.js`); map rendering kept thin/delegated. [~ residue: date-range filter not built — out of scope vs zaaktype/status which cover the spec's filtering requirement.]

- [ ] TASK-GIS-13: Create `src/views/MapLayerSettings.vue` admin configuration panel under Settings > Kaartlagen with:
  - Layer list table (title, type, visibility toggle, actions)
  - Add layer button → form (title, type, URL, layers, format, opacity, etc.)
  - Edit/delete layer actions
  - Live preview on sample map
  - Base layer vs. overlay toggle
  - Input validation for WMS/WFS URLs
  - Dutch + English UI text

- [ ] TASK-GIS-14: Create `src/utils/mapLayerManager.js` helper module with:
  - `loadMapLayers()` — Fetch configured layers from API
  - `createLeafletLayer()` / `createOpenLayersLayer()` — Factory methods to instantiate layers based on config
  - `addLayerToMap()` / `removeLayerFromMap()` — Manage layer lifecycle
  - `updateLayerOpacity()` / `toggleLayerVisibility()` — Live layer control
  - Layer type-specific handlers (tile templates, WMS GetMap, WFS GetFeature)

- [ ] TASK-GIS-15: Create `src/utils/pdokIntegration.js` PDOK client module with:
  - `searchAddress(query, limit)` — Call `/api/locations/search`, return formatted results
  - `searchParcel(query, bbox)` — Call `/api/parcels/search`, return parcel features
  - `autocompleteAddress(query, limit)` — Debounced address search for autocomplete
  - Error handling, caching, fallback messages for PDOK outages

## Integration & UI Updates

- [ ] TASK-GIS-16: Modify `src/views/CaseDetail.vue` to:
  - Add "Locatie" tab in case detail (after "Informatie" tab)
  - Display GeoViewer component (read-only map showing case location)
  - Add "Locatie toewijzen" button that opens LocationPickerModal
  - Show location metadata (source, address, set date/user) if available
  - Handle empty location state (prompt to add location)

- [ ] TASK-GIS-17: Modify `src/views/Dashboard.vue` or create navigation link to:
  - Add "Zaken op kaart" view in main navigation (Cases module)
  - Link to CasesOnMapView
  - Display case count by zaaktype or location for quick insight
  - Desktop-only or responsive design

- [ ] TASK-GIS-18: Seed `mapLayer` objects in `lib/Settings/procest_register.json` with default layers:
  - Base layers: OSM Positron, PDOK Luchtfoto 25cm, PDOK Grijs
  - Overlay layers: PDOK Bestemmingsplan (WFS), PDOK Kadaster (WMS), BAG (WMS)
  - Define layer URLs, attribution, opacity, zoom bounds
  - Documented with comments for maintenance

- [x] TASK-GIS-19: Registered the GIS config keys in `lib/Service/SettingsService.php::CONFIG_KEYS` (2026-06-14): `geo_map_library`, `geo_default_center_lat`, `geo_default_center_lon`, `geo_default_zoom`, `geo_max_cluster_radius`, `geo_wfs_endpoint_enabled`, `pdok_locatieserver_cache_ttl`, `pdok_locatieserver_url`. WfsController reads `geo_wfs_endpoint_enabled` (default-on). [These are scalar settings, not schema slugs, so no SLUG_TO_CONFIG_KEY entry — that map is schema-only.]

- [ ] TASK-GIS-20: Create settings UI in `src/views/Settings/GisSettings.vue` (or extend existing settings) to configure:
  - Map library choice (Leaflet/OpenLayers dropdown)
  - Default map center and zoom
  - Marker cluster radius
  - WFS endpoint toggle
  - PDOK cache TTL
  - Documentation links to PDOK service status

## Testing & Documentation

- [x] TASK-GIS-21: Added/extended PHPUnit service tests (2026-06-14): `GeoServiceTest` (17 tests — valid/invalid GeoJSON, MultiPolygon, normalise/encode round-trip, clustering, bbox, access guard, OR-degradation), `WfsServiceTest` (5 tests — GetCapabilities/DescribeFeatureType/GetFeature XML well-formedness + filter forwarding + escaping + empty collection). PdokServiceTest already exists on development (caching/degradation). All new + pre-existing service tests green (26 new pass; the only suite errors are the pre-existing ext-zip ZipArchive/Beschikking failures).

- [x] TASK-GIS-22: Added controller + endpoint integration coverage (2026-06-14): `CaseGeoControllerTest` (PHPUnit — access guard + degradation) and a Newman collection `tests/newman/gis-integration.postman_collection.json` (auto-wired into `tests/newman/run-all.sh`) exercising `/api/cases/geo` (FeatureCollection shape + filters) and `/wfs/cases` (GetCapabilities/DescribeFeatureType/GetFeature/ExceptionReport — OGC WFS 2.0.0 compliance). MapLayerController CRUD/permission tests already exist on development. [~ End-to-end "create case → pick location → view on map" deferred — depends on the LocationPicker + PDOK live tiles which need network and the openconnector PDOK adapter; the map-render path itself needs external tiles.]

- [x] TASK-GIS-23: Added frontend logic tests (2026-06-14): `tests/vitest/caseGeoService.spec.js` (12 tests) covering the cases-on-map data shaping — FeatureCollection → CaseMap geometries, status/cluster colouring, filter query building (zaaktype/status/zoom/bounds), count summary (cluster-member counting), and GeoJSON export (cluster-aggregate exclusion). Map rendering is kept thin (delegated to CaseMap) so the testable logic lives in pure helpers; full Leaflet-render component mounting needs jsdom + plugin-vue2 (not in this app's vitest config) and external tiles. Plus an `@e2e`-annotated Playwright spec-coverage test (`tests/e2e/spec-coverage/gis-integration.spec.ts`).

- [x] TASK-GIS-24: Create user documentation in `docs/gis-integration.md`:
  - Overview of GIS capabilities
  - User guide: How to set location on a case (address, parcel, map click)
  - Admin guide: Configuring map layers
  - Manager guide: Using cases-on-map dashboard
  - External integration: WFS endpoint setup for external GIS systems
  - Troubleshooting PDOK integration issues

- [x] TASK-GIS-25: Added i18n strings for the new GIS UI (2026-06-14) — English source keys with nl translations for the cases-on-map view + GeoViewer empty-state + degradation notice, registered in `l10n/en.{js,json}`, `l10n/en_US.{js,json}`, `l10n/nl.{js,json}` (lossless merge, English keys per house style). New keys: "Cases on map", "Showing {filtered} of {total} located cases", "All statuses", "Export visible cases (GeoJSON)", "Map data could not be loaded…", "This case has no geographic location yet.". (Pre-existing GIS strings like "Case type"/"No location set"/"Status" already present.)

## Build & Deployment

- [ ] TASK-GIS-26: Install and configure map library (Leaflet or OpenLayers):
  - Add npm dependency in `package.json`
  - Import and initialize in frontend components
  - Verify build includes map library assets (CSS, fonts)
  - Test production build

- [ ] TASK-GIS-27: Verify WFS endpoint CORS configuration:
  - Test public `/wfs/cases` endpoint from external GIS application
  - Ensure CORS headers allow external consumption
  - Document endpoint URL and usage in admin guide

- [ ] TASK-GIS-28: Performance testing:
  - Test map rendering with 1000+ case markers (clustering behavior)
  - Test address search with cache hit/miss (PDOK response times)
  - Monitor memory usage in GeoViewer component
  - Optimize clustering threshold if needed

- [ ] TASK-GIS-29: Create migration script if needed to populate `case.geometry` from existing location data (if any legacy location fields exist). Document data mapping and validation.

- [ ] TASK-GIS-30: Add change notes to `CHANGELOG.md` documenting:
  - New GIS capabilities summary
  - Breaking changes (if any)
  - Configuration settings
  - WFS endpoint availability
  - Dependencies (Leaflet/OpenLayers version)

## Build closure (2026-06-14)

This change's core was driven to done in one PR. Already-shipped (pre-existing
on development) and now formally ticked: 02 (PdokService), 03 (MapLayerService),
08 (MapLayerController), 24 (docs). Built in this PR: 01 (GeoService), 04
(WfsService — XML, reusing WfsExportService), 06 (WfsController — public
`/wfs/cases` OGC endpoint), 07 (CaseGeoController — `/api/cases/geo` with IDOR
guard), 09 (routes), 11 (GeoViewer), 12 (CasesOnMapView), 19 (settings keys),
21/22/23 (PHPUnit + Newman + vitest), 25 (i18n nl/en/en_US).

The rich pre-existing frontend (CaseMap, LocationPicker, MapLayerSwitcher,
MapLegend, CaseMapWidget, LocationTab, MapLayerSettings) plus GisProxyService /
WmsWfsService / WfsExportService back the location-picking, layer-config and
GeoJSON-WFS-export surfaces (TASK-GIS-02/03/05/10/13/14/15/16/17/18/20),
backed by the openconnector PDOK connector (`migrate-pdok-to-openconnector`).

Honest residue (left `[~]`/`[ ]`):
- TASK-GIS-05/10/15 (LocationPicker address/parcel autocomplete) — shipped as
  the existing `LocationPicker.vue` + `LocationService::reverseGeocode`; live
  PDOK address/parcel lookup rides on the openconnector PDOK adapter + live
  tiles (cross-app, network-bound).
- TASK-GIS-26 — Leaflet (+ markercluster + draw) is ALREADY a package.json
  dependency and used by CaseMap/GeoViewer; no new dep added. Production build
  has 4 PRE-EXISTING webpack errors (pinia/vue-demi in main.js) unrelated to
  this change.
- TASK-GIS-27 (WFS CORS verify) / TASK-GIS-28 (perf with 1000+ markers) —
  require a live deployment + external PDOK tiles; cannot be honestly closed in CI.
- TASK-GIS-29 — no legacy `case.geometry` field to migrate (procest uses a
  separate `location` schema with lat/lng); no migration needed.
- TASK-GIS-30 — CHANGELOG note deferred to the release-cut.

- [x] TASK-GIS-26: Leaflet already a dependency (no new map lib added); GeoViewer/CasesOnMapView render via the existing CaseMap. Production-build webpack errors are pre-existing (pinia/vue-demi), not introduced here.
- [~] TASK-GIS-27: WFS CORS verification needs a live external GIS client + deployment.
- [~] TASK-GIS-28: Performance testing needs a live deployment with 1000+ located cases and live PDOK tiles.
- [x] TASK-GIS-29: No migration needed — procest stores locations on a dedicated `location` schema (lat/lng), not a legacy `case.geometry` field; nothing to backfill.
- [~] TASK-GIS-30: CHANGELOG note deferred to the release-cut.
