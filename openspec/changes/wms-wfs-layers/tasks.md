# Tasks: wms-wfs-layers

## Implementation Tasks

### Schema & Configuration

- [ ] **T01**: Add `wmsLayer` schema to `lib/Settings/procest_register.json` with fields: `title`, `type` (enum WMS/WFS), `url`, `layerName`, `srs`, `opacity` (0.0–1.0), `attribution`, `queryable`, `format`, `version`, `isDefault`. Add `layerIds: array<string>` field to existing `caseType` schema. Register `wmsLayer` in `SettingsService::CONFIG_KEYS` and `SLUG_TO_CONFIG_KEY`. **Feature tier**: V1

### Backend Services

- [ ] **T02**: Create `lib/Service/WmsWfsService.php` — Methods: `getCapabilities(layerObject)` fetches `?REQUEST=GetCapabilities` via the existing GIS proxy and returns parsed layer list / supported SRS / formats (cached 1 h); `buildGetMapUrl(layerObject, bbox, width, height)` returns a proxy URL with WMS params (caps WIDTH/HEIGHT at 512); `buildGetFeatureUrl(layerObject, bbox)` returns a proxy URL with WFS bbox filter; `testConnection(url, type)` admin helper that returns `{ ok, layers[], error }`. MUST verify the URL is on the GIS proxy allowlist before issuing any request. **Feature tier**: V1

### Frontend — Admin

- [ ] **T03**: Add admin page for `wmsLayer` to `src/manifest.json` using `type: 'index'` (no `component` key, per ADR-008). Page title "Kaartlagen", route `/settings/wms-layers`, columns title/type/url/queryable, schema-driven form with "Verbinding testen" button that calls `WmsWfsService::testConnection`. **Feature tier**: V1

- [ ] **T04**: Update `src/views/caseTypes/CaseTypeDetail.vue` — Add "Kaartlagen" tab containing a multiselect of all `wmsLayer` objects. On save, persist selected ids into `caseType.layerIds` via the existing `caseType` object store. Show a preview swatch + attribution line for each selected layer. **Feature tier**: V1

### Frontend — Map

- [ ] **T05**: Extend `src/components/map/CaseMap.vue` (or the `CnMapPage` wrapper used by the case overview) with a `layerIds: string[]` prop. On mount/prop-change: resolve to `wmsLayer` objects (plus any `isDefault: true` layers), instantiate Leaflet WMS / WFS layers via `WmsWfsService`, attach to the map. Toggling a layer off MUST detach it and cancel in-flight requests. WFS layers with visible extent > 50 km × 50 km MUST be dimmed with a "Zoom in voor details" message. **Feature tier**: V1

- [ ] **T06**: Create `src/components/map/MapLayerLegend.vue` — Legend with per-layer row (checkbox, title, opacity slider 0–100, attribution). Escapes attribution text (no HTML). Binds GetFeatureInfo popup only when `layer.queryable === true`. Emits `toggle` and `opacity-change` events the `CaseMap` consumes. **Feature tier**: V1

## Verification Tasks

- [ ] **V01**: `wmsLayer` schema validates against OpenRegister and round-trips through ObjectService (create / list / update / delete).
- [ ] **V02**: Admin "Verbinding testen" against `https://service.pdok.nl/lv/bag/wms/v2_0` returns the BAG layer list parsed from GetCapabilities.
- [ ] **V03**: Saving a `wmsLayer` with a URL outside the GIS proxy allowlist returns a 400 error and the object is NOT persisted.
- [ ] **V04**: On a case-type with 2 subscribed WMS layers and 1 unsubscribed WFS layer, the `CaseMap` renders exactly the 2 WMS overlays in the legend (plus any `isDefault: true` layers) and issues no network requests for the unsubscribed layer.
