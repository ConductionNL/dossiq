# Tasks

> **Build status (hydra audit 2026-06-10).** Substantial backend already ships on dev: `lib/Service/Pdok/PdokLocatieserverService.php` (`suggest`, `free`, `lookup`, `reverse` PDOK Locatieserver calls with caching), `lib/Service/Pdok/PdokBagService.php`, `lib/Service/LocationService.php` (case-geometry validation + reverse geocode + attach/list per case), `lib/Service/WmsWfsService.php` (layer resolution per caseType + proxy/getMap/getFeature URL builders), `lib/Service/WfsExportService.php`, `lib/Service/GisProxyService.php` (generic upstream proxy + getCapabilities). Missing: the dedicated WFS service publishing case-features as OGC 2.0.0 (the export-side counterpart of the import-side WmsWfsService), the Vue map viewer + drawing UI, and the case-geometry CRUD endpoints. Tasks remain [ ] as forward work but the foundations are in place.

## Backend Services

- [ ] TASK-GIS-01: Create `lib/Service/GeoService.php` with CRUD methods for case geometry (create, read, update, delete), GeoJSON validation (RFC 7946), and coordinate transformation helpers. Add unit tests for geometry validation and edge cases (MultiPolygon, empty coordinates).

- [ ] TASK-GIS-02: Create `lib/Service/PdokService.php` with PDOK Locatieserver integration: `searchAddress()` with autocomplete caching, `searchParcel()` by number or bbox, error handling for PDOK outages, and local caching layer using Nextcloud cache with 1-hour TTL. Add integration tests with mock PDOK responses.

- [ ] TASK-GIS-03: Create `lib/Service/MapLayerService.php` with CRUD for `mapLayer` objects, layer filtering by type (tile/wms/wfs/geojson), visibility toggling, and ordering. Add validation for layer URLs and WMS/WFS endpoint syntax.

- [ ] TASK-GIS-04: Create `lib/Service/WfsService.php` to generate OGC WFS 2.0.0-compliant XML responses: `GetCapabilities` (list case features), `GetFeature` (with bbox/BBOX filters, CRS handling), `DescribeFeatureType`. Add unit tests for WFS XML structure and bbox filtering.

## Backend Controllers & Routes

- [ ] TASK-GIS-05: Create `lib/Controller/LocationPickerController.php` with endpoints:
  - `POST /api/locations/search` — Address search via PdokService
  - `POST /api/parcels/search` — Parcel search via PdokService
  - `GET /api/cases/{caseId}/geometry` — Retrieve case geometry
  - `POST /api/cases/{caseId}/geometry` — Set/update case geometry
  - Add input validation, error handling, and rate limiting for PDOK calls.

- [ ] TASK-GIS-06: Create `lib/Controller/WfsController.php` with public WFS endpoint at `GET /wfs/cases` handling `GetCapabilities`, `GetFeature`, `DescribeFeatureType` requests. Implement BBOX filtering, CRS (EPSG:4326), and metadata responses per OGC spec.

- [ ] TASK-GIS-07: Create `lib/Controller/CaseGeoController.php` with endpoint `GET /api/cases/geo` returning clustered case locations for map view. Implement:
  - Query parameters: `zaaktype`, `status`, `zoom`, `bounds` (bbox)
  - Clustering logic (server-side or client-side decision)
  - Pagination for large result sets
  - Response with GeoJSON FeatureCollection + metadata (total, filtered count)

- [ ] TASK-GIS-08: Create `lib/Controller/MapLayerController.php` with admin REST endpoints:
  - `GET /api/map-layers` — List layers
  - `POST /api/map-layers` — Create layer
  - `PUT /api/map-layers/{id}` — Update layer
  - `DELETE /api/map-layers/{id}` — Delete layer
  - Add permission checks (admin-only), input validation for URLs and layer names.

- [ ] TASK-GIS-09: Register all new routes in `appinfo/routes.php` and add permission middleware (authenticated for internal endpoints, public for `/wfs/cases`). Document route structure in inline comments.

## Frontend Components

- [ ] TASK-GIS-10: Create `src/components/LocationPickerModal.vue` with:
  - Address search tab with PDOK autocomplete (debounce 300ms)
  - Parcel search tab with map-based selector
  - Map click tab for direct coordinate entry
  - Current location display and editing
  - Save button that validates geometry and updates case
  - Responsive design, keyboard navigation

- [ ] TASK-GIS-11: Create `src/components/GeoViewer.vue` embedded map component with:
  - Leaflet or OpenLayers initialization (use `geo_map_library` setting)
  - Base layer switcher (toggle between configured base layers)
  - Overlay toggles for WMS/WFS layers
  - Zoom controls (fit-to-location, zoom in/out)
  - Feature info popup on click (parcel details, etc.)
  - Responsive sizing, full-height container

- [ ] TASK-GIS-12: Create `src/views/CasesOnMapView.vue` full-screen map dashboard with:
  - Clustered marker display using Leaflet.markercluster or OpenLayers equivalent
  - Filter sidebar: zaaktype, status, date range
  - Case summary card on marker click (caseId, title, status)
  - Link to case detail from card
  - Layer toggles (background, overlays)
  - Export visible cases as GeoJSON
  - Responsive layout (sidebar collapsible on mobile)

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

- [ ] TASK-GIS-19: Update `lib/Service/SettingsService.php` to register new config keys:
  - `geo_map_library` (default: 'leaflet')
  - `geo_default_center_lat`, `geo_default_center_lon`, `geo_default_zoom`
  - `geo_max_cluster_radius`
  - `geo_wfs_endpoint_enabled` (default: true)
  - `pdok_locatieserver_cache_ttl` (default: 3600)
  - `pdok_locatieserver_url` (PDOK endpoint)
  - Add to `SLUG_TO_CONFIG_KEY` mapping

- [ ] TASK-GIS-20: Create settings UI in `src/views/Settings/GisSettings.vue` (or extend existing settings) to configure:
  - Map library choice (Leaflet/OpenLayers dropdown)
  - Default map center and zoom
  - Marker cluster radius
  - WFS endpoint toggle
  - PDOK cache TTL
  - Documentation links to PDOK service status

## Testing & Documentation

- [ ] TASK-GIS-21: Add unit tests for GeoService, PdokService, WfsService with:
  - Valid/invalid GeoJSON handling
  - PDOK response caching behavior
  - WFS XML generation (GetCapabilities, GetFeature, DescribeFeatureType)
  - Edge cases: empty bbox, invalid coordinates, CORS failures
  - Minimum 80% code coverage for services

- [ ] TASK-GIS-22: Add integration tests for:
  - LocationPickerController with mocked PDOK responses
  - WfsController WFS endpoint compliance (OGC WFS 2.0.0 spec)
  - CaseGeoController clustering logic and bbox filtering
  - MapLayerController CRUD operations and permission checks
  - End-to-end: create case → set location via picker → view on map

- [ ] TASK-GIS-23: Add frontend component tests (Vue Test Utils) for:
  - LocationPickerModal address search and autocomplete
  - GeoViewer map initialization and layer toggling
  - CasesOnMapView filtering and marker clicks
  - MapLayerSettings CRUD UI
  - Minimum 70% coverage for Vue components

- [ ] TASK-GIS-24: Create user documentation in `docs/gis-integration.md`:
  - Overview of GIS capabilities
  - User guide: How to set location on a case (address, parcel, map click)
  - Admin guide: Configuring map layers
  - Manager guide: Using cases-on-map dashboard
  - External integration: WFS endpoint setup for external GIS systems
  - Troubleshooting PDOK integration issues

- [ ] TASK-GIS-25: Add i18n strings for all UI text:
  - Component labels, buttons, placeholders (Dutch + English)
  - Error messages (address not found, PDOK outage, invalid geometry)
  - Layer names and descriptions
  - Register strings in `l10n/*.json` files

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
