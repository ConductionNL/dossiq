## 1. Dependencies and Schema Setup (V1)

- [x] 1.1 Install npm dependencies: `leaflet`, `leaflet.markercluster`, `leaflet-draw`, `proj4` as production dependencies in `package.json`
- [x] 1.2 Add `MapLayer` schema definition to `lib/Settings/procest_register.json` with properties: title, type (enum: tile/wms/wfs/geojson), url, layers, format, attribution, isDefault, isBaseLayer, opacity, minZoom, maxZoom, order, style, proxyEnabled
- [x] 1.3 Verify the repair step imports the MapLayer schema via ConfigurationService::importFromApp() and test with OpenRegister
- [x] 1.4 Configure webpack to handle Leaflet's icon image imports (fix default marker icon path issue with webpack)

## 2. Core Map Component (V1)

- [x] 2.1 Create `src/components/map/CaseMap.vue` — reusable Leaflet map component with props for center, zoom, geometries, and layers; lazy-loaded via dynamic import; includes PDOK BRT Achtergrondkaart as default tile layer
- [x] 2.2 Add PDOK base layer options to CaseMap: BRT Achtergrondkaart, BRT Grijs, Luchtfoto — switchable via layer control
- [x] 2.3 Create `src/components/map/MapLayerSwitcher.vue` — layer control panel showing base layers (radio) and overlay layers (checkbox) with opacity slider per overlay
- [x] 2.4 Create `src/components/map/MarkerCluster.vue` — wrapper around Leaflet.markercluster with color-coded cluster icons (green <10, yellow 10-50, orange 50-100, red >100) and spiderfy on max-zoom click
- [x] 2.5 Create `src/components/map/CasePopup.vue` — marker popup showing case title, identifier, status badge (colored), assignee, case type, and "Bekijk zaak" link to case detail
- [x] 2.6 Create `src/components/map/MapLegend.vue` — legend component showing status color mapping (green=closed, blue=active, orange=near-deadline, red=overdue)
- [x] 2.7 Add keyboard navigation support to CaseMap: arrow keys for panning, +/- for zoom, role="application", aria-label="Kaart met zaaklocaties"

## 3. PDOK Integration Service (V1)

- [x] 3.1 Create `src/services/pdokService.js` with suggest(), lookup(), free(), and reverse() methods calling the PDOK Locatieserver v3.1 API; implement 200ms debounce on suggest
- [x] 3.2 Create `src/components/map/AddressSearch.vue` — search input with PDOK autocomplete dropdown, showing type icon (address/street/place), display name, and municipality per result
- [x] 3.3 Add reverse geocoding integration: given WGS84 coordinates, fetch nearest BAG address and display as "Keizersgracht 100, 1015 AA Amsterdam" format; cache results in Pinia store
- [x] 3.4 Add Proj4js RD (EPSG:28992) to WGS84 (EPSG:4326) coordinate transformation utility in `src/services/coordinateService.js`; auto-detect RD coordinates (x: 0-300000, y: 300000-625000)

## 4. Location Picker (V1)

- [x] 4.1 Create `src/components/map/LocationPicker.vue` — modal dialog with map, address search bar, and geometry output; supports point placement (click) and polygon drawing (Leaflet.draw)
- [x] 4.2 Implement point mode: click on map places/moves marker, coordinates update in real-time, "Opslaan" emits GeoJSON Point
- [x] 4.3 Implement polygon mode: activate "Gebied tekenen" tool using Leaflet.draw polygon handler, double-click closes polygon, display area in m2, "Opslaan" emits GeoJSON Polygon
- [x] 4.4 Integrate AddressSearch in LocationPicker: selecting a PDOK result centers map and places marker at geocoded coordinates
- [x] 4.5 Add "Huidige locatie" button using browser Geolocation API to center map on user's GPS position (with permission prompt handling)

## 5. Case Detail Location Tab (V1)

- [x] 5.1 Create `src/views/cases/components/LocationTab.vue` — renders CaseMap centered on case geometry with marker/polygon, sidebar showing address (reverse geocoded), area (for polygons), and coordinates
- [x] 5.2 Add "Locatie" tab to the case detail view tab navigation; show tab always but display "Geen locatie ingesteld" placeholder when case has no geometry
- [x] 5.3 Add "Locatie toevoegen" / "Locatie wijzigen" button that opens LocationPicker; on save, update case geometry in OpenRegister via existing case store
- [x] 5.4 Display BAG information panel when viewing a case location: bouwjaar, oppervlakte, gebruiksdoel, status — fetched from PDOK BAG WFS

## 6. Case Map Overview (V1)

- [x] 6.1 Create `src/views/CaseMap.vue` — full-page map view with CaseMap component, loading all cases with geometry from OpenRegister
- [x] 6.2 Add "Kaart" item to Procest main navigation (router + nav component) linking to the CaseMap view
- [x] 6.3 Implement status-based marker coloring: green (closed), blue (active), orange (deadline within 5 days), red (overdue); derive from case status and deadline fields
- [x] 6.4 Add filter sidebar to CaseMap overview: case type multiselect, status toggle buttons, assignee filter ("Mijn zaken" toggle), combined filter badge showing active count
- [x] 6.5 Create `src/components/map/SpatialFilter.vue` — rectangle and polygon drawing tools for geographic case selection; selected cases displayed in a sidebar list with bulk action buttons
- [x] 6.6 Implement viewport-based case loading: fetch cases with geometry that intersect the current map bounds; refresh on pan/zoom with debounce

## 7. GIS Proxy Backend (V1)

- [x] 7.1 Create `lib/Controller/GisProxyController.php` with `proxy()` action accepting POST with target URL, query parameters, and request type (WMS GetMap, WFS GetFeature, GetCapabilities)
- [x] 7.2 Create `lib/Service/GisProxyService.php` implementing URL allowlist validation (only URLs matching stored MapLayer objects), request forwarding via cURL, and response caching (5-minute TTL via ICache)
- [x] 7.3 Add rate limiting to GIS proxy: max 100 requests per minute per user using Nextcloud's IRateLimitingBackend or APCu counter
- [x] 7.4 Register API routes in `appinfo/routes.php`: `POST /api/gis/proxy` and `GET /api/gis/capabilities`
- [x] 7.5 Implement `capabilities()` action in GisProxyController: fetch GetCapabilities XML, parse available layers (title, name, CRS, formats), return as JSON for the admin layer configuration UI
- [x] 7.6 Create `src/services/gisProxyService.js` — frontend API client for the GIS proxy and capabilities endpoints

## 8. Admin Layer Management (V1)

- [x] 8.1 Create `src/views/settings/MapLayerSettings.vue` — admin settings page for managing MapLayer objects: list, add, edit, delete, reorder via drag-and-drop
- [x] 8.2 Add PDOK preset layer selector: dropdown with common PDOK layers (Kadastrale kaart, BAG, Bestemmingsplannen, CBS Wijken/buurten, AHN3, Natura 2000) that auto-fills URL, layer name, format, attribution
- [x] 8.3 Add "Verbinding testen" button that calls the capabilities endpoint and shows available layers with success/error indicator
- [x] 8.4 Register the "Kaartlagen" section in admin settings navigation (AdminRoot.vue)

## 9. GIS Store Module (V1)

- [x] 9.1 Create `src/store/modules/gis.js` — Pinia store with state for configured layers, active overlays, spatial filter geometry, reverse geocode cache, and CRUD actions for MapLayer objects via OpenRegister API
- [x] 9.2 Add spatial filter state management: selectedArea (GeoJSON), selectedCases (filtered by point-in-polygon), filter mode (rectangle/polygon/wijk)
- [x] 9.3 Add reverse geocode cache: store address lookups keyed by coordinate hash to avoid repeated PDOK calls

## 10. Case Creation Location (V1)

- [x] 10.1 Add optional "Locatie" section to case creation form with embedded LocationPicker (inline, not modal); geometry saved with case on creation
- [x] 10.2 Add `requiresLocation` boolean field to case type schema in `procest_register.json`; when true, case creation validates that geometry is set before saving
- [x] 10.3 Display validation message "Dit zaaktype vereist een locatie" when required location is missing

## 11. Dashboard Map Widget (V1)

- [x] 11.1 Create `src/views/dashboard/CaseMapWidget.vue` — compact map widget (400px min-height) for the Procest dashboard showing current user's assigned cases with marker clustering
- [x] 11.2 Register the map widget in the dashboard widget registry alongside existing widgets

## 12. Webpack and Build Configuration (V1)

- [x] 12.1 Configure dynamic import chunk for map components: all files in `src/components/map/` and leaflet dependencies should be code-split into a `map` chunk
- [x] 12.2 Fix Leaflet marker icon path issue: configure webpack to correctly resolve `leaflet/dist/images/marker-icon.png` and related assets
- [x] 12.3 Add Leaflet CSS import in CaseMap.vue (scoped to avoid global style conflicts)
