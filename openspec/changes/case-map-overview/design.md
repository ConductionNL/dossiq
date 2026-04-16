# Design: case-map-overview

## Architecture Overview

```
CaseMapView.vue  (route: /map, nav: "Kaart")
├── CaseMap.vue               ← Leaflet map, PDOK tiles, clustering, status coloring
│   ├── MapLegend.vue         ← Color legend (bottom-right corner)
│   ├── MapLayerSwitcher.vue  ← Base layer + overlay switcher
│   └── CasePopup.vue         ← Marker click popup (title, id, status badge, assignee, type, link)
└── SpatialFilter.vue         ← Toolbar: rectangle / polygon / wijk selection, area display
    └── SpatialSelectionSidebar.vue  ← Slide-in sidebar: selected case list + count + bulk actions

Dashboard.vue
└── CaseMapWidget.vue         ← Compact map (assigned cases, 400px min-height, clustering)

gis.js (Pinia store)
└── ObjectStore               ← CRUD on mapLayer objects in OpenRegister
```

## Reuse Analysis

The following OpenRegister / @conduction/nextcloud-vue capabilities are used directly — no custom alternatives needed:

| Capability | Where reused |
|---|---|
| `createObjectStore` / `useObjectStore` | `gis.js` fetches `mapLayer` objects via OpenRegister CRUD |
| `case.geometry` (JSON field, ADR-000) | Source of all map features — no extra schema |
| `mapLayer` schema (ADR-000, Group 7) | Stores PDOK/WFS/WMS layer configs |
| GIS proxy (`/api/gis/proxy`) | Forwards CBS WFS requests (CORS-restricted) to backend |
| OpenRegister filter params | `?geometry[ne]=null` to fetch only geo-enriched cases |
| `NcButton`, `NcCheckboxRadioSwitch`, `NcSelect` | Filter panel controls |
| `CnWidgetWrapper` | Dashboard widget container (via Dashboard.vue integration) |

No new schemas are introduced. No new PHP services or controllers are needed beyond the existing `GisProxyController`.

## File Map

### New Files

| File | Purpose |
|------|---------|
| `src/views/cases/components/SpatialSelectionSidebar.vue` | Slide-in sidebar listing cases selected by spatial tool; shows count, case rows, bulk-action hooks |

### Modified Files

| File | Change |
|------|--------|
| `src/components/map/SpatialFilter.vue` | Add wijk/buurt selection mode (CBS WFS layer click → select all cases within wijk polygon); add polygon area display (m²/ha via Leaflet GeometryUtil) |
| `lib/Settings/procest_register.json` | Add seed objects: 5 `case` records with `geometry`, 5 `mapLayer` records |

### Already Implemented (no change required)

| File | Status |
|------|--------|
| `src/views/CaseMapView.vue` | Filter panel with case type, status toggles, my-cases toggle, active filter count badge |
| `src/components/map/CaseMap.vue` | Leaflet + PDOK tiles + markercluster + status colors + auto-fit bounds |
| `src/components/map/CasePopup.vue` | Popup: title, identifier, status badge, case type, assignee, router-link "Bekijk zaak" |
| `src/components/map/MapLegend.vue` | Color legend (green/blue/orange/red) |
| `src/components/map/MapLayerSwitcher.vue` | Base layer / overlay switcher UI |
| `src/components/map/SpatialFilter.vue` | Rectangle + polygon draw tools (leaflet-draw) |
| `src/views/dashboard/CaseMapWidget.vue` | Dashboard map widget, 400px min-height, current-user filter |
| `src/store/modules/gis.js` | Pinia store for mapLayer CRUD + spatial filter state |
| `src/navigation/MainMenu.vue` | "Map" navigation item → CaseMap route |
| `src/router/index.js` | Route `/map` → CaseMapView |
| `appinfo/routes.php` | GIS proxy routes: POST `/api/gis/proxy`, GET `/api/gis/capabilities` |

## Data Model

This change uses **existing** entities from ADR-000. No new schemas.

### case (ADR-000, Group 2)

Relevant fields for the map:

| Field | Type | Use |
|-------|------|-----|
| `geometry` | string (JSON object) | GeoJSON Point, LineString, or Polygon for the case location |
| `status` | uuid | Current status — determines marker color category |
| `deadline` | date | Used for "nearing deadline" (within 5 days) and "overdue" color categories |
| `caseType` | uuid | Filter dimension |
| `assignee` | string | Filter dimension ("Mijn zaken") |
| `title` | string | Shown in popup |
| `identifier` | string | Shown in popup |

### mapLayer (ADR-000, Group 7)

Configures which tile/WMS/WFS layers appear in the layer switcher and wijk-selection overlay.

| Field | Use |
|-------|-----|
| `title` | Display name in switcher |
| `layerType` | `tile` / `wms` / `wfs` / `geojson` |
| `url` | Service URL |
| `layers` | WMS/WFS layer name |
| `isDefault` | Auto-loaded on init |
| `isBaseLayer` | Mutual-exclusion group |
| `opacity` | Layer transparency |
| `proxyEnabled` | Route via GIS proxy for CORS-restricted services |

## API Endpoints

No new endpoints. Existing endpoints used:

| Method | URL | Purpose |
|--------|-----|---------|
| GET | `/api/objects/case?geometry[ne]=null` | Fetch cases with geometry (OpenRegister filter) |
| GET | `/api/objects/mapLayer` | Fetch configured map layers |
| POST | `/api/gis/proxy` | Forward CBS WFS / other CORS-restricted GIS requests |
| GET | `/api/gis/capabilities` | Check GIS proxy availability |

## Seed Data

Seed objects to add to `lib/Settings/procest_register.json` under `components.objects`.

### mapLayer objects (5)

```json
{
  "@self": { "register": "procest", "schema": "mapLayer", "slug": "layer-pdok-brt-standaard" },
  "title": "PDOK BRT Achtergrondkaart",
  "layerType": "tile",
  "url": "https://service.pdok.nl/brt/achtergrondkaart/wmts/v2_0/standaard/EPSG:3857/{z}/{x}/{y}.png",
  "attribution": "Kaartgegevens © Kadaster",
  "isDefault": true,
  "isBaseLayer": true,
  "maxZoom": 19,
  "order": 1
}

{
  "@self": { "register": "procest", "schema": "mapLayer", "slug": "layer-pdok-brt-grijs" },
  "title": "PDOK BRT Grijs",
  "layerType": "tile",
  "url": "https://service.pdok.nl/brt/achtergrondkaart/wmts/v2_0/grijs/EPSG:3857/{z}/{x}/{y}.png",
  "attribution": "Kaartgegevens © Kadaster",
  "isDefault": false,
  "isBaseLayer": true,
  "maxZoom": 19,
  "order": 2
}

{
  "@self": { "register": "procest", "schema": "mapLayer", "slug": "layer-pdok-luchtfoto" },
  "title": "PDOK Luchtfoto Actueel",
  "layerType": "tile",
  "url": "https://service.pdok.nl/hwh/luchtfotorgb/wmts/v1_0/Actueel_orthoHR/EPSG:3857/{z}/{x}/{y}.jpeg",
  "attribution": "Luchtfoto © Kadaster",
  "isDefault": false,
  "isBaseLayer": true,
  "maxZoom": 19,
  "order": 3
}

{
  "@self": { "register": "procest", "schema": "mapLayer", "slug": "layer-cbs-wijken" },
  "title": "CBS Wijken en Buurten",
  "layerType": "wfs",
  "url": "https://service.pdok.nl/cbs/wijkenbuurten/2023/wfs/v1_0",
  "layers": "wijken",
  "attribution": "CBS Wijken © CBS / PDOK",
  "isDefault": false,
  "isBaseLayer": false,
  "opacity": 0.4,
  "proxyEnabled": false,
  "style": "{\"color\": \"#1976D2\", \"weight\": 2, \"fillColor\": \"#1976D2\", \"fillOpacity\": 0.1}",
  "order": 10
}

{
  "@self": { "register": "procest", "schema": "mapLayer", "slug": "layer-bag-panden-wms" },
  "title": "BAG Panden",
  "layerType": "wms",
  "url": "https://service.pdok.nl/lv/bag/wms/v2_0",
  "layers": "pand",
  "format": "image/png",
  "attribution": "BAG Panden © Kadaster / PDOK",
  "isDefault": false,
  "isBaseLayer": false,
  "opacity": 0.7,
  "proxyEnabled": false,
  "order": 20
}
```

### case objects with geometry (5)

Dutch locations: Amsterdam, Rotterdam, Utrecht, Den Haag, Eindhoven. Uses `statusType` slugs from existing seed data.

```json
{
  "@self": { "register": "procest", "schema": "case", "slug": "case-geo-amsterdam-klacht" },
  "title": "Klacht overlast bouwwerkzaamheden Jordaan",
  "identifier": "2026-0101",
  "geometry": "{\"type\": \"Point\", \"coordinates\": [4.8897, 52.3740]}",
  "priority": "high",
  "assignee": "admin"
}

{
  "@self": { "register": "procest", "schema": "case", "slug": "case-geo-rotterdam-omgevingsvergunning" },
  "title": "Omgevingsvergunning uitbouw Kralingse Zoom",
  "identifier": "2026-0102",
  "geometry": "{\"type\": \"Point\", \"coordinates\": [4.5300, 51.9225]}",
  "priority": "normal",
  "assignee": "admin"
}

{
  "@self": { "register": "procest", "schema": "case", "slug": "case-geo-utrecht-subsidie" },
  "title": "Subsidie verduurzaming Lombok",
  "identifier": "2026-0103",
  "geometry": "{\"type\": \"Point\", \"coordinates\": [5.0914, 52.0907]}",
  "priority": "normal",
  "assignee": "admin"
}

{
  "@self": { "register": "procest", "schema": "case", "slug": "case-geo-denhaag-handhaving" },
  "title": "Handhaving illegale reclame-uiting Centrum",
  "identifier": "2026-0104",
  "geometry": "{\"type\": \"Point\", \"coordinates\": [4.3007, 52.0705]}",
  "priority": "urgent",
  "assignee": "admin"
}

{
  "@self": { "register": "procest", "schema": "case", "slug": "case-geo-eindhoven-melding" },
  "title": "Melding gevaarlijke situatie fietspad Strijp-S",
  "identifier": "2026-0105",
  "geometry": "{\"type\": \"Point\", \"coordinates\": [5.4551, 51.4381]}",
  "priority": "high",
  "assignee": "admin"
}
```

## Component Details

### SpatialSelectionSidebar.vue (new)

- Shown when spatial selection is active and at least one case is selected
- Props: `cases` (array), `wijkName` (string, for wijk mode), `wijkCode` (string)
- Emits: `close`, `case-click(id)`
- Shows: case count header, scrollable case list (title + identifier + status badge), "Sluit selectie" button
- If `wijkName` provided: shows wijk name and code in sidebar header
- Bulk actions section rendered only if user has delete/assign permissions (check via settings store)

### SpatialFilter.vue additions

**Polygon area display:**
- After polygon draw completes, calculate area using `L.GeometryUtil.geodesicArea(latLngs)`
- Display: `< 10 000 m² → show m²`, `≥ 10 000 m² → show ha (rounded to 2 decimal places)`
- Shown below the toolbar while polygon selection is active

**Wijk/buurt selection:**
- New button "Selecteer wijk" (third tool button)
- When active: fetch CBS wijken WFS layer from `gisStore.layers` where `layerType === 'wfs' && layers === 'wijken'`
- Load wijk polygons via `GET /api/gis/proxy` (or direct if `proxyEnabled === false`)
- Add a transparent WFS layer to the map; cursor changes to crosshair
- On wijk polygon click: emit `selection-change` with the wijk polygon geometry
- Emit extra event `wijk-selected({ name, code, geometry })` for sidebar header
- Disable wijk mode when another tool is selected or "Clear" is clicked
