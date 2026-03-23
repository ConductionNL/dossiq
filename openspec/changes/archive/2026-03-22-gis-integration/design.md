## Context

Procest is a thin-client Nextcloud app for case management. It stores all data in OpenRegister (JSON object storage) and renders a Vue 2.7 frontend that queries OpenRegister's API directly. The backend is minimal: SettingsController + ConfigurationService for register setup.

Cases already have a `geometry` field (GeoJSON string, type `string`) in the register schema at `lib/Settings/procest_register.json`. The field exists but is currently unused -- there are no map components, no GIS libraries, and no location-related UI anywhere in the app.

This change introduces Leaflet-based map views, PDOK integration for base maps and address search, WMS/WFS overlay layers, and a backend GIS proxy for CORS handling.

## Goals / Non-Goals

**Goals:**
- Add a reusable `CaseMap.vue` component using Leaflet.js for all map rendering needs
- Display case location on the case detail view in a "Locatie" tab
- Provide a location picker (point + polygon) with PDOK Locatieserver address search
- Add a "Kaart" navigation item showing all cases on an overview map with clustering and filtering
- Support admin-configurable WMS/WFS overlay layers with PDOK presets
- Implement a backend GIS proxy endpoint for CORS-restricted external services
- Support coordinate transformation between RD (EPSG:28992) and WGS84 (EPSG:4326)
- Add a `MapLayer` schema to OpenRegister for storing layer configurations
- Add a dashboard map widget

**Non-Goals:**
- Full GIS analysis (buffering, intersection, spatial joins)
- Offline map tiles (covered by mobiel-inspectie)
- 3D/BIM visualization
- PostGIS or server-side spatial queries
- Own tile server

## Decisions

### D1: Leaflet.js (not OpenLayers)

**Decision**: Use Leaflet.js as the map library.

**Alternatives considered**:
- OpenLayers: More powerful but significantly larger bundle (~300KB vs ~40KB), steeper learning curve, overkill for our use case.
- Mapbox GL JS: Requires API key, commercial license for production, proprietary vector tiles.
- Google Maps: License cost, no PDOK native support, vendor lock-in.

**Rationale**: Leaflet is lightweight, well-documented, has excellent plugin ecosystem (clustering, draw, WMS), and is the de facto standard for Dutch government web applications (used by PDOK viewer, geo.overheid.nl, kadaster viewer). The Leaflet.markercluster and Leaflet.draw plugins cover our clustering and polygon drawing needs.

### D2: Direct PDOK API calls (no backend wrapper for public APIs)

**Decision**: Call PDOK Locatieserver and tile services directly from the frontend. Only use the backend proxy for CORS-restricted WMS/WFS from third-party servers.

**Alternatives considered**:
- Route all requests through backend: Unnecessary latency; PDOK services have proper CORS headers.
- Use a separate geocoding service: PDOK is free, authoritative for NL addresses, no API key needed.

**Rationale**: PDOK services support CORS and are publicly accessible. Adding a backend layer would increase latency without benefit. The proxy is only needed for municipal/third-party WMS/WFS servers that don't set CORS headers.

### D3: MapLayer stored in OpenRegister (not app config)

**Decision**: Store layer configurations as OpenRegister objects with a `MapLayer` schema.

**Alternatives considered**:
- Nextcloud IAppConfig key-value: Limited to flat strings, no proper schema validation.
- PHP config file: Not manageable by functional administrators.

**Rationale**: Consistent with Procest's thin-client pattern. Administrators manage layers through the same UI pattern as other Procest objects. OpenRegister gives us validation, API access, and audit trail.

### D4: Lazy-load map components

**Decision**: Code-split all map-related components into a separate webpack chunk that loads on demand.

**Rationale**: Leaflet + plugins + Proj4js add ~80KB gzipped. Users who never visit map views should not pay this cost. Use dynamic `import()` in Vue router and component definitions.

### D5: GIS proxy as Nextcloud API route

**Decision**: Implement the GIS proxy as a standard Nextcloud API controller route (`/api/gis/proxy`) with URL allowlisting based on configured MapLayer objects.

**Alternatives considered**:
- Separate proxy service (nginx, Caddy): Over-engineering; adds deployment complexity.
- PHP stream proxy: Standard approach for Nextcloud apps; matches existing patterns.

**Rationale**: The proxy handles a simple pass-through with caching. A standard Nextcloud controller with `file_get_contents` or cURL keeps it simple. The allowlist is derived from stored MapLayer URLs, preventing open proxy abuse.

## Component Architecture

```
src/
  components/
    map/
      CaseMap.vue              # Core reusable map component (Leaflet wrapper)
      MapLayerSwitcher.vue     # Base layer + overlay toggle panel
      MarkerCluster.vue        # Wrapper for Leaflet.markercluster
      LocationPicker.vue       # Point/polygon picker with address search
      AddressSearch.vue        # PDOK Locatieserver search input
      CasePopup.vue            # Marker popup with case summary
      SpatialFilter.vue        # Rectangle/polygon/wijk selection tool
      MapLegend.vue            # Status color legend
  views/
    CaseMap.vue                # Full-page map overview (navigation route)
    cases/
      components/
        LocationTab.vue        # Case detail location tab
  store/
    modules/
      gis.js                   # Pinia store: layers, spatial filter state
  services/
    pdokService.js             # PDOK Locatieserver API client
    gisProxyService.js         # Backend GIS proxy API client
lib/
  Controller/
    GisProxyController.php     # WMS/WFS proxy endpoint
  Service/
    GisProxyService.php        # URL allowlist, caching, request forwarding
```

## Schema Addition

Add `MapLayer` schema to `lib/Settings/procest_register.json`:

```json
{
  "title": "MapLayer",
  "description": "GIS map layer configuration for case maps",
  "properties": {
    "title": { "type": "string", "required": true },
    "type": { "type": "string", "enum": ["tile", "wms", "wfs", "geojson"], "required": true },
    "url": { "type": "string", "format": "uri", "required": true },
    "layers": { "type": "string" },
    "format": { "type": "string", "default": "image/png" },
    "attribution": { "type": "string" },
    "isDefault": { "type": "boolean", "default": false },
    "isBaseLayer": { "type": "boolean", "default": false },
    "opacity": { "type": "number", "minimum": 0, "maximum": 1, "default": 1.0 },
    "minZoom": { "type": "integer" },
    "maxZoom": { "type": "integer" },
    "order": { "type": "integer", "default": 0 },
    "style": { "type": "string", "description": "JSON-encoded style object" },
    "proxyEnabled": { "type": "boolean", "default": false }
  }
}
```

## API Routes

| Method | Path | Controller | Description |
|--------|------|-----------|-------------|
| POST | `/api/gis/proxy` | GisProxyController::proxy | Forward WMS/WFS requests to allowed URLs |
| GET | `/api/gis/capabilities` | GisProxyController::capabilities | Fetch and parse GetCapabilities for a URL |

## Dependencies (npm)

| Package | Version | Size (gzip) | Purpose |
|---------|---------|-------------|---------|
| `leaflet` | ^1.9 | ~40KB | Map rendering |
| `leaflet.markercluster` | ^1.5 | ~10KB | Marker clustering |
| `leaflet-draw` | ^1.0 | ~15KB | Polygon/rectangle drawing |
| `proj4` | ^2.9 | ~15KB | RD/WGS84 coordinate transform |

All are loaded via dynamic import to avoid main bundle impact.
