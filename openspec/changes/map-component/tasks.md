# Tasks: map-component

## Implementation Tasks

### Dependencies

- [ ] **T01**: Add npm dependencies to `package.json` — `leaflet`, `vue2-leaflet`, `leaflet.markercluster`, `proj4`, `proj4leaflet`. Run `npm install`. Confirm versions are compatible with Vue 2.

### Store

- [ ] **T02**: Create `src/store/modules/mapLayer.js` — Pinia store using `createObjectStore('mapLayer', 'mapLayer', 'procest')` with `filesPlugin` and `relationsPlugin`. Register in `src/store/store.js` via `initializeStores()` call: `mapLayerStore.registerObjectType('mapLayer', 'mapLayer', 'procest')`.

### Utilities

- [ ] **T03**: Create `src/utils/coordinateConverter.js` — Export function `convertToWGS84(coordinates, sourceCrs)`. When `sourceCrs === 'EPSG:28992'`, use `proj4` with the EPSG:28992 definition string to convert to EPSG:4326. Function accepts a GeoJSON coordinate array (any depth — handle Point/LineString/Polygon/MultiPolygon recursively). Return converted coordinate array in WGS84 order `[lng, lat]` compatible with GeoJSON RFC 7946. Include EPSG:28992 proj4 definition string as a constant.

### Cluster Icon Factory

- [ ] **T04**: Create `src/components/CaseMapClusterIcon.js` — Export function `createClusterIcon(cluster)` for use as `iconCreateFunction` in `L.markerClusterGroup`. Determine count from `cluster.getChildCount()`. Apply CSS class and label: green `cn-cluster-small` (<10), yellow `cn-cluster-medium` (10-50), orange `cn-cluster-large` (50-100), red `cn-cluster-xlarge` (>100). Return `L.divIcon` with count as inner HTML and matching class. Include CSS for all four cluster classes using Nextcloud CSS variables for colours.

### CaseMap Component

- [ ] **T05**: Create `src/components/CaseMap.vue` — Props:
  - `geometry` (Object|null): GeoJSON geometry object. If null, show empty map centred on Netherlands.
  - `geometries` (Array, default `[]`): Array of `{ geometry, title, identifier }` for multi-point clustering.
  - `crs` (String, default `'EPSG:4326'`): Coordinate reference system of incoming geometry. When `'EPSG:28992'`, convert via `coordinateConverter.js`.
  - `height` (String, default `'400px'`): CSS height of the map container.
  - `interactive` (Boolean, default `true`): When false, disable pan/zoom (for dashboard thumbnails).
  
  Behaviour:
  - `mounted()`: initialise Leaflet map on `this.$refs.mapContainer`. Load `mapLayer` objects from store; add `isBaseLayer` layers to `L.control.layers` base layers group, add non-base layers as overlays. Set default base layer (`isDefault: true`). Add zoom control, attribution, layer switcher. Apply `role="application"` and `aria-label` to map container div.
  - Watch `geometry` prop: re-render geometry layer when prop changes.
  - `beforeDestroy()`: call `map.remove()` to clean up Leaflet instance and event listeners.
  - Geometry rendering: use `L.geoJSON` for Polygon/LineString/MultiPolygon with default style (`{color: '#1E88E5', weight: 2, fillColor: '#1E88E5', fillOpacity: 0.2}`); use `L.markerClusterGroup` (from `leaflet.markercluster`) with `createClusterIcon` for Point geometries. Auto-zoom to bounds when geometry is non-null.
  - Keyboard: enable Leaflet keyboard handler (`keyboard: true` in map options). Ensure `tabindex="0"` on container.
  - Responsive: use `ResizeObserver` on the container element; on resize, call `map.invalidateSize()`.
  - Popups: on marker click, bind popup with `<strong>${title}</strong><br/>${identifier}`.

- [ ] **T06**: Register `CaseMap` in `src/main.js` or import directly in consuming components. Ensure Leaflet CSS (`leaflet/dist/leaflet.css`) and markercluster CSS (`leaflet.markercluster/dist/MarkerCluster.css`, `MarkerCluster.Default.css`) are imported in the component or globally.

### Admin Settings Components

- [ ] **T07**: Create `src/views/settings/components/MapLayerFormDialog.vue` — `CnFormDialog`-based form for creating and editing `mapLayer` objects. Fields:
  - `title` (text input, required)
  - `layerType` (NcSelect: tile / wms / wfs / geojson, required)
  - `url` (text input, required, placeholder shows example URL for selected type)
  - `layers` (text input, shown only when layerType is wms or wfs, label "WMS/WFS laagnamen")
  - `attribution` (text input)
  - `isBaseLayer` (checkbox, label "Achtergrondlaag")
  - `isDefault` (checkbox, label "Standaard laag", only enabled when isBaseLayer is true)
  - `opacity` (number input 0.0-1.0, step 0.1, default 1.0)
  - `minZoom` (number input 1-21)
  - `maxZoom` (number input 1-21)
  - `order` (number input, label "Volgorde")
  Props: `mapLayerObject` (Object|null, null = new), `show` (Boolean). Emits: `@close`, `@saved`.

- [ ] **T08**: Create `src/views/settings/components/MapLayerSettings.vue` — Settings section for map layer management. On `mounted()`: fetch all `mapLayer` objects from store. If result is empty, seed default PDOK layers (call a `seedDefaultLayers()` method that creates the 3 default tile layers: BRT Achtergrondkaart as isDefault+isBaseLayer, BRT Grijs as isBaseLayer, Luchtfoto as isBaseLayer). Template: `CnSettingsSection` with title "Kaartlagen". List all layers in a `CnDataTable` with columns: order, title, layerType, isDefault badge, opacity. Row actions: edit (opens `MapLayerFormDialog`), delete (opens `CnDeleteDialog`). Add button opens `MapLayerFormDialog` with null object. On save/delete: refresh list from store.

### Integration

- [ ] **T09**: Integrate `CaseMap` into `src/views/cases/CaseDetail.vue` — Import `CaseMap`. In the template, add a `CnDetailCard` with title `t('procest', 'Locatie')` that renders `<CaseMap :geometry="parsedGeometry" :height="'350px'" />` when `parsedGeometry` is not null. Add computed `parsedGeometry` that parses `activeCase.geometry` from JSON string to object (handle parse errors gracefully, return null on failure). Register `CaseMap` in `components: {}`.

- [ ] **T10**: Integrate `MapLayerSettings` into `src/views/settings/AdminRoot.vue` — Import `MapLayerSettings`. Add `<MapLayerSettings />` as a new section in the admin settings template, after the existing register mapping section. Register in `components: {}`.

## Verification Tasks

- [ ] **V01**: Map renders with PDOK BRT Achtergrondkaart on initial load; zoom controls and attribution are visible
- [ ] **V02**: Layer switcher shows 3 PDOK base layers; switching replaces the current base layer
- [ ] **V03**: Case with Point geometry renders a marker at the correct WGS84 coordinates; clicking shows popup with title and identifier
- [ ] **V04**: Case with Polygon geometry renders a filled polygon; map auto-zooms to fit bounds
- [ ] **V05**: Geometry in EPSG:28992 (e.g., `[155000, 463000]`) is correctly converted and renders on Amsterdam location
- [ ] **V06**: 500 point geometries at zoom 7 render as clusters with colour-coded icons (green/yellow/orange/red)
- [ ] **V07**: Clicking a cluster zooms to split level; at max zoom, spiderfies to show individual markers
- [ ] **V08**: Arrow keys pan the map; `+`/`-` keys zoom; map has `role="application"` and correct `aria-label`
- [ ] **V09**: Resizing the browser window (or toggling sidebar) triggers `invalidateSize()` and map fills its container
- [ ] **V10**: Admin settings shows mapLayer list; add/edit/delete operations work correctly
- [ ] **V11**: When no mapLayer objects exist, default PDOK layers are seeded on first admin settings load
- [ ] **V12**: CaseDetail renders map card only when `case.geometry` is non-null; hidden when geometry is absent
