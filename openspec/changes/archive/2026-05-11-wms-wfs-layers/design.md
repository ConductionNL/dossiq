# Design: wms-wfs-layers

## Architecture Overview

`wms-wfs-layers` is a config-driven extension of the existing `map-component`. Admins describe each WMS/WFS endpoint as a `wmsLayer` object; each `caseType` carries an array of subscribed `layerIds`; the `CaseMap` reads the active case type, resolves its layer set, and renders the overlays plus a legend. All network access flows through the existing GIS proxy (REQ-LAYER-03 in the parent `wms-wfs-layers` spec) — this change does not introduce a new backend network path.

```
Admin
└── Settings > Kaartlagen (manifest index page on wmsLayer)
    └── Kaartlaag form (title, type, url, layerName, srs, opacity, attribution, queryable)

Case-type admin
└── "Kaartlagen" tab (multiselect of wmsLayer objects → caseType.layerIds)

Case/overview map
└── CaseMap (CnMapPage)
    ├── layerIds prop (from active caseType)
    ├── WmsWfsService client (GetMap / GetFeature via /api/gis/proxy)
    ├── Layer toggle + opacity slider (per-layer)
    └── Legend renderer (title + colour swatch + attribution)
```

## File Map

### New Backend Files

| File | Purpose |
|------|---------|
| `lib/Service/WmsWfsService.php` | Client for GetCapabilities, GetMap, GetFeature; routes all outbound traffic through `GisProxyController`; parses capabilities XML for admin "test connection" feature |

### New Frontend Files

| File | Purpose |
|------|---------|
| `src/components/map/MapLayerLegend.vue` | Legend component: per-layer toggle, opacity slider, attribution line |

### Modified Files

| File | Changes |
|------|---------|
| `lib/Settings/procest_register.json` | Add `wmsLayer` schema; add `layerIds: array` field to existing `caseType` schema |
| `lib/Service/SettingsService.php` | Add `wmsLayer` to CONFIG_KEYS and SLUG_TO_CONFIG_KEY |
| `src/manifest.json` | Add admin `index` page on `wmsLayer` (no `component` key — manifest-driven per ADR-008) |
| `src/views/caseTypes/CaseTypeDetail.vue` | Add "Kaartlagen" tab with multiselect of available `wmsLayer` objects |
| `src/components/map/CaseMap.vue` (or `CnMapPage` wrapper) | Add `layerIds` prop; on mount resolve to `wmsLayer` objects; render overlays via `WmsWfsService`; mount `MapLayerLegend` |

## Data Model

### wmsLayer Schema (new)

| Field | Type | Description | Required |
|-------|------|-------------|----------|
| `title` | string | Display name in legend and layer switcher | Yes |
| `type` | enum (`WMS` / `WFS`) | OGC service type | Yes |
| `url` | string | Service base URL (must match GIS proxy allowlist) | Yes |
| `layerName` | string | WMS layer name (`LAYERS=` param) or WFS typeName | Yes |
| `srs` | string | Spatial reference system (default `EPSG:3857`; `EPSG:28992` for RD) | No |
| `opacity` | number (0.0–1.0) | Default overlay opacity | No (default `0.7`) |
| `attribution` | string | Attribution text shown in legend | No |
| `queryable` | boolean | Whether GetFeatureInfo (WMS) or property popup (WFS) is enabled | No (default `false`) |
| `format` | string | Image format for WMS (e.g. `image/png`) | No (default `image/png`) |
| `version` | string | OGC version (`1.3.0` for WMS, `2.0.0` for WFS) | No (auto-detect) |
| `isDefault` | boolean | Show on initial load even without subscription | No (default `false`) |

### caseType Schema (modified)

Add field:

| Field | Type | Description | Required |
|-------|------|-------------|----------|
| `layerIds` | array<string (UUID)> | Subscribed `wmsLayer` ids visible on this case type's maps | No (default `[]`) |

## Subscription Resolution

1. `CaseMap` receives a `caseTypeId` (or reads it from the active case).
2. It fetches the `caseType` object, reads `layerIds`.
3. It batch-fetches the referenced `wmsLayer` objects from OpenRegister.
4. It augments with any `wmsLayer` where `isDefault: true` (independent of case type).
5. It hands the resolved layer set to `MapLayerLegend` and to the Leaflet tile/feature factory.

## Performance Considerations

- **Lazy load**: `WmsWfsService` only fetches tiles/features when a layer is toggled on. Toggling off detaches the layer and cancels in-flight requests.
- **Tile size cap**: WMS `WIDTH`/`HEIGHT` requested from the proxy is capped at 512×512. Larger viewport areas are tiled by Leaflet (default 256×256 grid).
- **WFS bbox guard**: GetFeature requests always carry a `BBOX` filter restricted to the visible map extent; if the extent exceeds 50 km × 50 km the layer is dimmed and a "Zoom in voor details" message replaces the features.
- **Capabilities cache**: Parsed GetCapabilities responses are cached server-side for 1 hour (admin "test connection" refresh button bypasses cache).
- **Per-tenant**: All `wmsLayer` objects live in the tenant's OpenRegister; no cross-tenant layer leakage.

## Security & Compliance

- Layer URLs MUST resolve to an entry in the GIS proxy allowlist (parent spec REQ-LAYER-03c). Saving a `wmsLayer` with a URL outside the allowlist is rejected at the API layer.
- `queryable: false` layers MUST NOT issue GetFeatureInfo requests — enforced both in `MapLayerLegend` (no popup binding) and in `WmsWfsService` (proxy parameter validation).
- Attribution text is rendered as escaped text — no HTML interpolation in the legend.
