# Design: map-component

## Architecture Overview

The map component is a self-contained Vue 2 component that wraps Leaflet. It accepts GeoJSON geometry as a prop, fetches configured `mapLayer` objects from the store, and renders them. The component has no knowledge of the case entity — it is purely a geometry/layer visualizer.

```
CaseDetail.vue
└── CaseMap.vue (geometry prop, layers from store)
    ├── Leaflet map instance (mounted/beforeDestroy lifecycle)
    ├── L.TileLayer — one per isBaseLayer mapLayer
    ├── L.LayerGroup + L.markerClusterGroup — for Point geometries
    └── L.GeoJSON — for Polygon/LineString/MultiPolygon geometries

AdminRoot.vue
└── MapLayerSettings.vue (CRUD for mapLayer objects)
    └── MapLayerFormDialog.vue (add/edit form)

src/store/modules/mapLayer.js (createObjectStore)
src/utils/coordinateConverter.js (proj4 RD→WGS84)
```

## File Map

### New Files

| File | Purpose |
|------|---------|
| `src/components/CaseMap.vue` | Primary reusable map component — Leaflet init, base layers, geometry rendering, clustering, keyboard nav, accessibility attributes |
| `src/components/CaseMapClusterIcon.js` | Cluster icon factory — returns coloured DivIcon based on count (<10 green, 10-50 yellow, 50-100 orange, >100 red) |
| `src/utils/coordinateConverter.js` | `convertToWGS84(coordinates, sourceCrs)` — uses proj4 to convert EPSG:28992 (RD) to EPSG:4326 (WGS84); returns GeoJSON-compatible coordinate array |
| `src/views/settings/components/MapLayerSettings.vue` | Admin settings section — list of mapLayer objects with add/edit/delete/reorder; seeds default PDOK layers on first load |
| `src/views/settings/components/MapLayerFormDialog.vue` | CnFormDialog-based form for creating and editing mapLayer objects — fields: title, layerType, url, layers, format, attribution, isDefault, isBaseLayer, opacity, minZoom, maxZoom, order, style, proxyEnabled |
| `src/store/modules/mapLayer.js` | Pinia object store via `createObjectStore('mapLayer', 'mapLayer', 'procest')` with `filesPlugin` and `relationsPlugin` |

### Modified Files

| File | Changes |
|------|---------|
| `src/views/cases/CaseDetail.vue` | Import and render `CaseMap` in a `CnDetailCard` when `case.geometry` is non-null; pass parsed geometry as `:geometry` prop |
| `src/views/settings/AdminRoot.vue` | Import and render `MapLayerSettings` as a new settings section after the register mapping section |
| `src/store/store.js` | Register `mapLayer` object store in `initializeStores()` |
| `package.json` | Add dependencies: `leaflet`, `vue2-leaflet`, `leaflet.markercluster`, `proj4`, `proj4leaflet` |

## Design Decisions

### DD-01: Leaflet over MapLibre GL

**Decision**: Use Leaflet (via vue2-leaflet) rather than MapLibre GL JS.

**Rationale**: PDOK tile services work equally well with Leaflet. Leaflet has stable Vue 2 bindings, a smaller bundle size, and the existing `leaflet.markercluster` plugin covers the clustering requirement. MapLibre GL adds vector tile complexity that is not needed for V1.

### DD-02: Coordinate conversion client-side with proj4

**Decision**: Perform EPSG:28992 → EPSG:4326 coordinate conversion in the browser using `proj4` + `proj4leaflet`.

**Rationale**: Case geometry is stored as raw GeoJSON which may arrive in either RD or WGS84. Converting client-side avoids a round-trip and allows the CaseMap component to be self-contained. The conversion is only triggered when the `crs` prop is set to `"EPSG:28992"`.

### DD-03: mapLayer objects drive layer configuration

**Decision**: All tile layer configuration is stored as `mapLayer` OpenRegister objects, not hardcoded in the component.

**Rationale**: Municipalities use different base maps (some use BGT, others prefer aerial). Storing configuration in OpenRegister allows admins to add, remove, and reorder layers without code changes. The component fetches layers from the store on mount.

### DD-04: Default PDOK layers seeded by admin settings component

**Decision**: The `MapLayerSettings.vue` component seeds three default PDOK layers when none exist (on first admin settings page load).

**Rationale**: Zero-configuration startup experience. Admins can delete or modify the defaults. Seeding is idempotent — it only runs if the store has zero mapLayer objects after the initial fetch.

## Seed Data — mapLayer

Five example `mapLayer` objects with Dutch values for seeding and testing:

```json
[
  {
    "title": "PDOK BRT Achtergrondkaart",
    "layerType": "tile",
    "url": "https://service.pdok.nl/brt/achtergrondkaart/wmts/v2_0/standaard/EPSG:3857/{z}/{x}/{y}.png",
    "attribution": "© Kadaster",
    "isDefault": true,
    "isBaseLayer": true,
    "opacity": 1.0,
    "minZoom": 1,
    "maxZoom": 19,
    "order": 1
  },
  {
    "title": "PDOK BRT Achtergrondkaart Grijs",
    "layerType": "tile",
    "url": "https://service.pdok.nl/brt/achtergrondkaart/wmts/v2_0/grijs/EPSG:3857/{z}/{x}/{y}.png",
    "attribution": "© Kadaster",
    "isDefault": false,
    "isBaseLayer": true,
    "opacity": 1.0,
    "minZoom": 1,
    "maxZoom": 19,
    "order": 2
  },
  {
    "title": "PDOK Luchtfoto",
    "layerType": "tile",
    "url": "https://service.pdok.nl/hwh/luchtfotorgb/wmts/v1_0/Actueel_ortho25/EPSG:3857/{z}/{x}/{y}.jpeg",
    "attribution": "© Beeldmateriaal Nederland",
    "isDefault": false,
    "isBaseLayer": true,
    "opacity": 1.0,
    "minZoom": 1,
    "maxZoom": 21,
    "order": 3
  },
  {
    "title": "BAG Adressen (WMS)",
    "layerType": "wms",
    "url": "https://service.pdok.nl/kadaster/bag/wms/v2_0",
    "layers": "pand,verblijfsobject",
    "format": "image/png",
    "attribution": "© Kadaster - BAG",
    "isDefault": false,
    "isBaseLayer": false,
    "opacity": 0.7,
    "minZoom": 12,
    "maxZoom": 21,
    "order": 4
  },
  {
    "title": "Bestemmingsplannen (WMS)",
    "layerType": "wms",
    "url": "https://afnemers.ruimtelijkeplannen.nl/afnemers/services",
    "layers": "BP,BESTEMMINGSPLANGEBIED",
    "format": "image/png",
    "attribution": "© LVNL - Ruimtelijke Plannen",
    "isDefault": false,
    "isBaseLayer": false,
    "opacity": 0.5,
    "minZoom": 10,
    "maxZoom": 21,
    "order": 5,
    "style": "{\"color\": \"#e65100\", \"weight\": 2, \"fillColor\": \"#ff9800\", \"fillOpacity\": 0.15}"
  }
]
```

## API Endpoints

The map component uses no new backend API endpoints. It reads `mapLayer` objects directly from the OpenRegister REST API via the existing object store pattern. Case geometry is already available on the `case` object via `GET /api/objects/{uuid}`.
