# Tasks: wms-wfs-layers

## Implementation Tasks

### Schema & Configuration

- [x] **T01**: Add `wmsLayer` schema to `lib/Settings/procest_register.json` with fields: `title`, `type` (enum WMS/WFS), `url`, `layerName`, `srs`, `opacity` (0.0–1.0), `attribution`, `queryable`, `format`, `version`, `isDefault`. Add `layerIds: array<string>` field to existing `caseType` schema. Register `wmsLayer` in `SettingsService::CONFIG_KEYS` and `SLUG_TO_CONFIG_KEY`. **Feature tier**: V1

### Backend Services

- [x] **T02**: Create `lib/Service/WmsWfsService.php` — Methods: `getLayersForCaseType(caseType)`, `validateLayer(layer)`, `proxyRequest(layer, params)`, `getLayerById(id)`, `buildGetMapUrl(layer, bbox, width, height)`, `buildGetFeatureUrl(layer, bbox)`. All outbound HTTP delegated to `GisProxyService::proxyRequest` which enforces the allowlist. Tile cap 512, WFS bbox required, 50km extent cutoff. Action endpoint exposed via `/api/wms-wfs/proxy` (`WmsWfsController`). **Feature tier**: V1

### Frontend — Admin

- [x] **T03**: Added `WmsLayers` (`type: 'index'`) + `WmsLayerDetail` (`type: 'detail'`) pages to `src/manifest.json` (no `component` key, per ADR-008). Route `/settings/wms-layers`, columns title/type/url/layerName/queryable/active, sidebar tabs with "Verbinding testen" action wired to `/api/wms-wfs/proxy?layerId=:id&request=GetCapabilities`. Menu entry `WmsLayersMenu` under `settings` section, admin-only. **Feature tier**: V1

- [ ] **T04**: Update `src/views/caseTypes/CaseTypeDetail.vue` — Add "Kaartlagen" tab containing a multiselect of all `wmsLayer` objects. On save, persist selected ids into `caseType.layerIds` via the existing `caseType` object store. Show a preview swatch + attribution line for each selected layer. **Feature tier**: V1 — (deferred: manifest-driven via `caseType.layerIds` field; no hand-written Vue needed for storage. Optional future tab can be added when CaseTypeDetail is migrated to manifest.)

### Frontend — Map

- [ ] **T05**: Extend `src/components/map/CaseMap.vue` (or the `CnMapPage` wrapper used by the case overview) with a `layerIds: string[]` prop. On mount/prop-change: resolve to `wmsLayer` objects (plus any `isDefault: true` layers), instantiate Leaflet WMS / WFS layers via `WmsWfsService`, attach to the map. Toggling a layer off MUST detach it and cancel in-flight requests. WFS layers with visible extent > 50 km × 50 km MUST be dimmed with a "Zoom in voor details" message. **Feature tier**: V1 — (frontend follow-up: backend `getLayersForCaseType()` and `/api/wms-wfs/proxy` ready for Leaflet to consume.)

- [ ] **T06**: Create `src/components/map/MapLayerLegend.vue` — Legend with per-layer row (checkbox, title, opacity slider 0–100, attribution). Escapes attribution text (no HTML). Binds GetFeatureInfo popup only when `layer.queryable === true`. Emits `toggle` and `opacity-change` events the `CaseMap` consumes. **Feature tier**: V1 — (frontend follow-up.)

## Verification Tasks

- [ ] **V01**: `wmsLayer` schema validates against OpenRegister and round-trips through ObjectService (create / list / update / delete).
- [ ] **V02**: Admin "Verbinding testen" against `https://service.pdok.nl/lv/bag/wms/v2_0` returns the BAG layer list parsed from GetCapabilities.
- [ ] **V03**: Saving a `wmsLayer` with a URL outside the GIS proxy allowlist returns a 400 error and the object is NOT persisted.
- [ ] **V04**: On a case-type with 2 subscribed WMS layers and 1 unsubscribed WFS layer, the `CaseMap` renders exactly the 2 WMS overlays in the legend (plus any `isDefault: true` layers) and issues no network requests for the unsubscribed layer.
