# Design: gis-integration

## Architecture Overview

GIS integration layers onto the existing case infrastructure without modifying the core case lifecycle. The design splits cleanly:

1. **Data Layer** — Case geometry stored as GeoJSON in the `case.geometry` field (defined in ADR-000)
2. **Backend Services** — PDOK integration (geocoding, parcel lookup) and WFS endpoint for external consumption
3. **Frontend Components** — Embedded Leaflet/OpenLayers map for location picking and case visualization
4. **Configuration** — Administrator-managed map layer definitions stored in the `mapLayer` schema

```
┌─────────────────────────────────────────────────────────────┐
│ Frontend (Vue)                                               │
│  ├─ LocationPickerModal.vue (address search + map click)    │
│  ├─ GeoViewer.vue (embedded map with case location)         │
│  ├─ CasesOnMapView.vue (overview map with clustering)       │
│  └─ MapLayerSettingsPanel.vue (admin layer configuration)   │
└─────────────────────────────────────────────────────────────┘
                           │
                           ▼
┌─────────────────────────────────────────────────────────────┐
│ Backend API (PHP/Laravel)                                    │
│  ├─ GeoService (geometry CRUD, WFS generation)              │
│  ├─ PdokService (address search, parcel lookup)             │
│  ├─ MapLayerService (layer configuration)                   │
│  ├─ LocationPickerController (address/parcel endpoints)     │
│  ├─ WfsController (WFS GetCapabilities, GetFeature)         │
│  └─ CaseGeoController (map data for dashboard)              │
└─────────────────────────────────────────────────────────────┘
                           │
                           ▼
┌─────────────────────────────────────────────────────────────┐
│ External Services (read-only)                                │
│  ├─ PDOK Locatieserver (address geocoding)                  │
│  ├─ PDOK WMS/WFS services (tile layers)                     │
│  └─ BAG/BRK via PDOK (parcel lookup)                        │
└─────────────────────────────────────────────────────────────┘
```

## File Map

### New Backend Files

| File | Purpose |
|------|---------|
| `lib/Service/GeoService.php` | Geometry CRUD, GeoJSON validation, WFS feature generation |
| `lib/Service/PdokService.php` | PDOK Locatieserver address search, parcel lookup, caching |
| `lib/Service/MapLayerService.php` | Map layer CRUD, tile/WMS/WFS/GeoJSON layer management |
| `lib/Service/WfsService.php` | WFS GetCapabilities and GetFeature response generation |
| `lib/Controller/LocationPickerController.php` | REST endpoints for address/parcel search (used by LocationPickerModal) |
| `lib/Controller/WfsController.php` | WFS endpoint (read-only public endpoint) for case locations |
| `lib/Controller/CaseGeoController.php` | Map data for cases-on-map dashboard (filtered, clustered) |
| `lib/Controller/MapLayerController.php` | Admin CRUD for map layer configuration |

### New Frontend Files

| File | Purpose |
|------|---------|
| `src/components/LocationPickerModal.vue` | Modal with address autocomplete (PDOK), parcel selector, and map click to set location |
| `src/components/GeoViewer.vue` | Embedded map showing case location with configurable layers and controls |
| `src/views/CasesOnMapView.vue` | Overview map with all cases, clustering, filters by zaaktype/status |
| `src/views/MapLayerSettings.vue` | Admin panel for configuring map layers (tile, WMS, WFS, GeoJSON) |
| `src/utils/mapLayerManager.js` | Helper to load, render, and toggle Leaflet/OpenLayers layers |
| `src/utils/pdokIntegration.js` | PDOK Locatieserver client for address search and parcel lookup |

### Modified Files

| File | Changes |
|------|---------|
| `lib/Settings/procest_register.json` | Seed `mapLayer` schema (already defined in ADR-000) |
| `lib/Service/SettingsService.php` | Add `geo_map_library` (leaflet/openlayers), `geo_default_center` (lat/lon), `geo_default_zoom`, `geo_wfs_endpoint_enabled`, `pdok_locatieserver_cache_ttl` config keys |
| `appinfo/routes.php` | Add `/api/locations/search`, `/api/parcels/search`, `/api/cases/geo`, `/api/map-layers`, `/wfs/cases` routes |
| `src/views/CaseDetail.vue` | Add "Locatie" tab with GeoViewer and "Locatie toewijzen" button opening LocationPickerModal |
| `src/views/Dashboard.vue` | Add "Zaken op kaart" view linking to CasesOnMapView |
| `appinfo/info.xml` | Register settings keys |

## Data Model

### Case Geometry Field (existing, activated)
The `case` schema already includes a `geometry` field (defined in ADR-000):
- **geometry** (string, JSON-encoded) — GeoJSON FeatureCollection or Feature with Point, Polygon, or MultiPolygon geometry
  - Example Point: `{"type": "Feature", "geometry": {"type": "Point", "coordinates": [5.123, 52.456]}}`
  - Example Polygon (parcel): `{"type": "Feature", "geometry": {"type": "Polygon", "coordinates": [[[5.1, 52.1], [5.2, 52.1], [5.2, 52.2], [5.1, 52.2], [5.1, 52.1]]]}}`

### mapLayer Schema (existing, seed with defaults)
The `mapLayer` schema is already defined in ADR-000. Seed data will include:
- **Base layers**: OSM Positron, PDOK Luchtfoto, PDOK Grijs
- **Overlay layers**: PDOK Bestemmingsplan (WFS), PDOK Kadaster (WMS), PDOK BAG (WMS)

| Property | Type | Purpose |
|----------|------|---------|
| title | string | Display name in layer switcher |
| layerType | string | 'tile', 'wms', 'wfs', or 'geojson' |
| url | string | Service URL (tile template, WMS base, WFS endpoint, GeoJSON URL) |
| layers | string | WMS/WFS layer name(s), comma-separated |
| format | string | WMS image format (e.g., 'image/png') |
| attribution | string | Attribution text |
| isDefault | boolean | Show on initial load |
| isBaseLayer | boolean | Only one base layer visible at a time |
| opacity | number | 0.0–1.0 |
| minZoom, maxZoom | integer | Zoom level constraints |
| order | integer | Display order in switcher |
| style | string | JSON-encoded style object (color, weight, fillOpacity) for GeoJSON/WFS |
| proxyEnabled | boolean | Route through backend proxy for CORS-restricted services |

### Seed Data (mapLayer examples)
```json
[
  {
    "title": "PDOK Achtergrondkaart Grijs",
    "layerType": "tile",
    "url": "https://tile.openstreetmap.de/tiles/osmde/{z}/{x}/{y}.png",
    "attribution": "© OpenStreetMap contributors",
    "isDefault": true,
    "isBaseLayer": true,
    "order": 1
  },
  {
    "title": "PDOK Luchtfoto 25cm",
    "layerType": "tile",
    "url": "https://geodata.nationaalgeoregister.nl/luchtfoto/rgb/wmts?...",
    "attribution": "Kadaster",
    "isBaseLayer": true,
    "order": 2
  },
  {
    "title": "Bestemmingsplan (WFS)",
    "layerType": "wfs",
    "url": "https://geodata.nationaalgeoregister.nl/bestemmingsplan/wfs",
    "layers": "bestemmingsplangebied",
    "isDefault": false,
    "opacity": 0.7,
    "style": "{\"fillColor\": \"#FF6B6B\", \"weight\": 1, \"fillOpacity\": 0.3}"
  },
  {
    "title": "Kadasterkaart (WMS)",
    "layerType": "wms",
    "url": "https://geodata.nationaalgeoregister.nl/kadaster/wms",
    "layers": "percelen",
    "format": "image/png",
    "isDefault": false,
    "opacity": 0.6
  }
]
```

## Backend API Design

### Location Picker Endpoints

#### `POST /api/locations/search`
Search for addresses using PDOK Locatieserver.
```json
{
  "query": "Nieuwezijds Voorburgwal 147, Amsterdam",
  "limit": 10
}
```
Returns array of address suggestions:
```json
{
  "results": [
    {
      "id": "pdok-1234567",
      "label": "Nieuwezijds Voorburgwal 147, 1012 RE Amsterdam",
      "x": 4.8952,
      "y": 52.3745,
      "bagId": "0363100012345678",
      "type": "adres"
    }
  ]
}
```

#### `POST /api/parcels/search`
Search for cadastral parcels (by number or bounding box).
```json
{
  "query": "AMS01 A 1234",
  "bbox": [4.8, 52.3, 4.9, 52.4]
}
```
Returns:
```json
{
  "results": [
    {
      "id": "NL.KAD.PERCEEL.AMS01A1234",
      "label": "AMS01 A 1234",
      "geometry": {
        "type": "Polygon",
        "coordinates": [...]
      },
      "municipality": "Amsterdam",
      "area_m2": 12345
    }
  ]
}
```

#### `POST /api/cases/{caseId}/geometry`
Set or update case location.
```json
{
  "geometry": {
    "type": "Feature",
    "geometry": {
      "type": "Point",
      "coordinates": [4.8952, 52.3745]
    },
    "properties": {
      "source": "pdok-address",
      "address": "Nieuwezijds Voorburgwal 147, 1012 RE Amsterdam",
      "bagId": "0363100012345678"
    }
  }
}
```

#### `GET /api/cases/{caseId}/geometry`
Retrieve case location.
Returns:
```json
{
  "geometry": { ... },
  "setAt": "2026-05-21T10:30:00Z",
  "setBy": "user123"
}
```

### Map Dashboard Endpoints

#### `GET /api/cases/geo`
Retrieve clustered case locations for map view.
```
GET /api/cases/geo?zaaktype=omgevingsvergunning&status=in_behandeling&zoom=12&bounds=4.8,52.3,4.9,52.4
```
Returns:
```json
{
  "features": [
    {
      "id": "case-001",
      "type": "Feature",
      "geometry": { "type": "Point", "coordinates": [4.8952, 52.3745] },
      "properties": {
        "caseId": "2026-0001",
        "title": "Omgevingsvergunning",
        "zaaktype": "omgevingsvergunning",
        "status": "in_behandeling",
        "cluster": false
      }
    },
    {
      "id": "cluster-12",
      "type": "Feature",
      "geometry": { "type": "Point", "coordinates": [4.88, 52.37] },
      "properties": {
        "clusterCount": 15,
        "cluster": true
      }
    }
  ],
  "total": 247,
  "filtered": 142
}
```

### Map Layer Admin Endpoints

#### `GET /api/map-layers`
List all configured map layers.

#### `POST /api/map-layers`
Create a new map layer.
```json
{
  "title": "Bestemmingsplan gemeente",
  "layerType": "wfs",
  "url": "https://geodata.nationaalgeoregister.nl/bestemmingsplan/wfs",
  "layers": "bestemmingsplangebied",
  "isBaseLayer": false,
  "opacity": 0.7
}
```

#### `PUT /api/map-layers/{id}`
Update map layer configuration.

#### `DELETE /api/map-layers/{id}`
Delete a map layer.

### WFS Public Endpoint

#### `GET /wfs/cases`
OGC Web Feature Service endpoint (public, read-only).
```
GET /wfs/cases?service=WFS&version=2.0.0&request=GetCapabilities
GET /wfs/cases?service=WFS&version=2.0.0&request=GetFeature&typename=cases&BBOX=4.8,52.3,4.9,52.4,EPSG:4326
```

Exposes case locations as a standard WFS layer. External GIS applications can add this endpoint as a feature source.

## Frontend Components

### LocationPickerModal.vue
Modal opened from case detail "Locatie toewijzen" button.
- **Address Search Tab**: PDOK autocomplete (debounced API calls)
- **Parcel Search Tab**: Map-based parcel selector with search field
- **Map Click Tab**: Interactive map for clicking location
- **Current Location Display**: Shows existing geometry if set
- **Confirmation**: Saves selected location to case.geometry

### GeoViewer.vue
Embedded map shown in case detail "Locatie" tab.
- **Base Layer Switcher**: Toggle between available base layers
- **Overlay Toggles**: Show/hide WMS/WFS overlays (bestemmingsplan, kadaster, etc.)
- **Zoom Controls**: Fit-to-case-location, zoom in/out
- **Feature Info**: Click on overlays to show properties (parcel info, etc.)
- **Read-Only**: Display only (location editing via LocationPickerModal)

### CasesOnMapView.vue
Full-screen map dashboard for managers.
- **Clustered Markers**: Cases grouped at high zoom levels
- **Filter Sidebar**: Filter by zaaktype, status, date range
- **Case Card**: Click marker to show case summary with link to detail
- **Layer Toggles**: Show/hide background layers and overlays
- **Export**: Download visible cases as GeoJSON

### MapLayerSettings.vue
Admin configuration panel under Settings > Kaartlagen.
- **Layer List**: Table of existing layers with title, type, visibility
- **Add Layer Button**: Opens form to configure new WMS/WFS/tile/GeoJSON layer
- **Edit/Delete**: Modify or remove layers
- **Preview**: Test layer visibility on sample map
- **Base Layer / Overlay Toggle**: Configure layer role

## Configuration

### Settings Keys (in SettingsService)

| Key | Type | Default | Purpose |
|-----|------|---------|---------|
| `geo_map_library` | enum | 'leaflet' | 'leaflet' or 'openlayers' |
| `geo_default_center_lat` | float | 52.1326 | Default map center latitude (Netherlands) |
| `geo_default_center_lon` | float | 5.2913 | Default map center longitude |
| `geo_default_zoom` | integer | 7 | Default map zoom level |
| `geo_max_cluster_radius` | integer | 80 | Pixel radius for marker clustering |
| `geo_wfs_endpoint_enabled` | boolean | true | Enable public WFS endpoint |
| `pdok_locatieserver_cache_ttl` | integer | 3600 | Cache PDOK responses for 1 hour |
| `pdok_locatieserver_url` | string | `https://geodata.nationaalgeoregister.nl/locatieserver/...` | PDOK Locatieserver endpoint |

## Dependencies & Risks

### External Service Dependencies
- **PDOK Locatieserver** — Address geocoding, no authentication, cached responses
  - *Risk*: Outage blocks location picking (mitigation: cache responses, fallback to manual GPS entry)
  - *Mitigation*: Implement local fallback address database or use alternative geocoder (e.g., OSM Nominatim)

- **PDOK WMS/WFS Tile Services** — Public, read-only, CORS-enabled
  - *Risk*: Layer unavailability (mitigation: graceful degradation, clear error messages)
  - *Mitigation*: Monitor layer health, document tile service outages in admin UI

### Data Integrity
- **Geometry Storage** — GeoJSON validation on import; no projection conversion (assume EPSG:4326)
  - *Mitigation*: Validate GeoJSON syntax on case create/update; document supported projections

- **WFS Endpoint** — Case data exposed publicly (read-only, no case details)
  - *Mitigation*: WFS returns only location + minimal case metadata (caseId, status); access control enforced via standard WFS filters if needed

### Performance
- **Large Case Sets on Map** — Clustering mitigates marker explosion
  - *Mitigation*: Implement server-side pagination and zoom-aware tile generation; limit query results

- **PDOK Cache** — Store address search results in-memory or Redis
  - *Mitigation*: Use Nextcloud cache layer (ARCCache) for PDOK responses; TTL = 1 hour

## Standards & Specifications

- **OGC Web Feature Service (WFS) 2.0.0** — For cases-as-layer endpoint
- **GeoJSON RFC 7946** — Geometry storage format
- **PDOK Public Datasets** — Luchtfoto, Bestemmingsplan, Kadaster, BAG
- **Leaflet.js** or **OpenLayers** — Web map library (to be evaluated)
- **EPSG:4326** — WGS 84 (all coordinates in lat/lon, no transformation)
