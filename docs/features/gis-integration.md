# GIS Integration

**Issue:** #91
**Branch:** `feature/91/gis-integration`
**PR:** #96

## Overview

Adds geographic information system (GIS) capabilities to Procest, allowing caseworkers to view cases on a map, pick locations for new cases, and overlay municipal WMS/WFS data layers. Integrates with PDOK (Dutch national geo-data infrastructure) and supports custom WMS/WFS layers.

## Architecture

### Backend

Two new PHP classes handle secure GIS data proxying:

**`lib/Service/GisProxyService`**
- Proxies WMS/WFS requests from the frontend to external GIS services
- URL allowlist: always allows `pdok.nl` and `kadaster.nl`; validates other URLs against configured MapLayer objects in OpenRegister
- Caching: APCu distributed cache with 5-minute TTL
- Rate limiting: 100 requests/minute per user
- GetCapabilities parsing: extracts WMS Layer / WFS FeatureType elements from XML
- XML-to-JSON conversion via SimpleXML

**`lib/Controller/GisProxyController`**
- `proxy()` endpoint — forwards WMS/WFS requests, returns `JSONResponse`
- `capabilities()` endpoint — fetches and parses GetCapabilities from a service URL
- Error codes: 400 (missing URL), 403 (URL blocked), 429 (rate limited), 502 (upstream error)

**`lib/Service/SettingsService`** — new config keys: `map_layer_schema`

### Frontend

- **Pinia store:** `src/store/modules/gis.js`
- **Vue components:** CaseMap, LocationPicker, AddressSearch, MapLayerSwitcher, MapLegend, SpatialFilter, CasePopup
- **Services:** `coordinateService.js`, `gisProxyService.js`, `pdokService.js`
- **Views:** CaseMapView, CaseMapWidget (dashboard), MapLayerSettings (admin), LocationTab (case detail)
- **Map library:** Leaflet (via npm)

## Security

All external GIS requests are proxied through Nextcloud to avoid CORS issues and to enforce the URL allowlist. Users cannot access GIS services that are not configured as MapLayer objects in OpenRegister.

## Testing

Unit tests are in:
- `tests/Unit/Service/GisProxyServiceTest.php` — URL allowlist validation, cache hit returns, rate limiting
- `tests/Unit/Controller/GisProxyControllerTest.php` — HTTP status codes for success/403/429/502, missing URL → 400
