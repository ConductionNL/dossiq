---
status: done
retrofit_extensions:
  - REQ-005
  - REQ-006
---

# Map Component Specification

## Purpose

@e2e exclude Map component is V1; Leaflet rendering and GIS proxy scenarios require geospatial test data not available in CI.

Provide a reusable Leaflet-based map component for Procest that renders GeoJSON geometries, supports multiple tile layers (PDOK), and can be embedded in case detail views, dashboards, and admin settings. The component handles coordinate system conversion (RD to WGS84), marker clustering for large datasets, and responsive sizing.

**Standards**: GeoJSON (RFC 7946), WGS84 (EPSG:4326), PDOK tile services, WCAG AA (keyboard navigation, alt text)
**Feature tier**: V1

## Data Model

### MapLayer Configuration (admin-managed, stored in OpenRegister)

| Property | Type | Description | Required |
|----------|------|-------------|----------|
| `title` | string | Display name for layer switcher | Yes |
| `type` | enum | `tile`, `wms`, `wfs`, `geojson` | Yes |
| `url` | string | Service URL (tile template, WMS base URL, WFS endpoint, or GeoJSON URL) | Yes |
| `layers` | string | WMS/WFS layer name(s), comma-separated | Conditional (WMS/WFS) |
| `format` | string | Image format for WMS (e.g., `image/png`) | No (default: `image/png`) |
| `attribution` | string | Attribution text for the layer | No |
| `isDefault` | boolean | Show this layer on initial load | No |
| `isBaseLayer` | boolean | If true, only one base layer visible at a time | No |
| `opacity` | number | Layer opacity 0.0-1.0 | No (default: 1.0) |
| `minZoom` | integer | Minimum zoom level for visibility | No |
| `maxZoom` | integer | Maximum zoom level for visibility | No |
| `order` | integer | Display order in layer switcher | No |
| `style` | object | GeoJSON/WFS feature style (color, weight, fillColor, fillOpacity) | No |
| `proxyEnabled` | boolean | Route requests through backend GIS proxy | No (default: false) |

## Requirements

---

### REQ-MAP-01: Base Map Component

The system MUST provide a reusable Vue map component based on Leaflet that can be embedded in any view.

**Feature tier**: V1

#### Scenario MAP-01a: Render map with PDOK base layer

- GIVEN the `CaseMap` component is mounted
- WHEN no custom layers are configured
- THEN the map MUST display the PDOK BRT Achtergrondkaart as the default base layer
- AND the map MUST be centered on the Netherlands (lat: 52.1326, lng: 5.2913, zoom: 7)
- AND the map MUST show zoom controls and attribution

#### Scenario MAP-01b: Base layer switcher

- GIVEN the map component is rendered
- WHEN the user clicks the layer switcher control
- THEN the following base layers MUST be available:
  - PDOK BRT Achtergrondkaart (default)
  - PDOK BRT Achtergrondkaart Grijs
  - PDOK Luchtfoto (aerial imagery)
- AND selecting a base layer MUST replace the current base layer (only one visible)

#### Scenario MAP-01c: Responsive sizing

- GIVEN the map is embedded in a container
- WHEN the container is resized (e.g., sidebar toggle)
- THEN the map MUST automatically resize to fill its container
- AND the map MUST call `invalidateSize()` after resize transitions complete

---

### REQ-MAP-02: GeoJSON Geometry Rendering

The map component MUST render GeoJSON geometries (Point, LineString, Polygon, MultiPolygon) with configurable styling.

**Feature tier**: V1

#### Scenario MAP-02a: Render case location point

- GIVEN a case with geometry `{"type": "Point", "coordinates": [5.1214, 52.0907]}`
- WHEN the map component receives this geometry as a prop
- THEN a marker MUST be displayed at the correct location
- AND clicking the marker MUST show a popup with the case title and identifier

#### Scenario MAP-02b: Render case area polygon

- GIVEN a case with geometry `{"type": "Polygon", "coordinates": [[[5.12, 52.09], [5.13, 52.09], [5.13, 52.10], [5.12, 52.10], [5.12, 52.09]]]}`
- WHEN the map component receives this geometry
- THEN the polygon MUST be rendered with the configured style (default: blue fill, 0.2 opacity)
- AND the map MUST auto-zoom to fit the polygon bounds

#### Scenario MAP-02c: Handle RD coordinates

- GIVEN geometry coordinates in Rijksdriehoekscoordinaten (EPSG:28992) format (e.g., `[155000, 463000]`)
- WHEN the geometry is passed to the map component with `crs: "EPSG:28992"`
- THEN the component MUST convert coordinates to WGS84 (EPSG:4326) using Proj4js before rendering
- AND the conversion MUST maintain sub-meter accuracy

---

### REQ-MAP-03: Marker Clustering

When displaying multiple case locations, the map MUST use marker clustering to maintain performance and readability.

**Feature tier**: V1

#### Scenario MAP-03a: Cluster markers at low zoom

- GIVEN 500 cases with point geometries
- WHEN displayed on the map at zoom level 7 (national view)
- THEN nearby markers MUST be grouped into cluster icons showing the count
- AND cluster icons MUST use color coding: green (<10), yellow (10-50), orange (50-100), red (>100)

#### Scenario MAP-03b: Expand clusters on zoom

- GIVEN a cluster of 25 cases in Amsterdam
- WHEN the user zooms in to street level (zoom 16+)
- THEN individual markers MUST become visible
- AND clicking a marker MUST show the case popup

#### Scenario MAP-03c: Cluster click behavior

- GIVEN a cluster icon showing "42"
- WHEN the user clicks the cluster
- THEN the map MUST zoom in to the next level where the cluster splits
- AND if at max zoom, the cluster MUST "spiderfy" to show individual markers

---

### REQ-MAP-04: Keyboard and Accessibility

The map component MUST be keyboard-navigable and meet WCAG AA requirements.

**Feature tier**: V1

#### Scenario MAP-04a: Keyboard navigation

- GIVEN the map component has focus
- WHEN the user presses arrow keys
- THEN the map MUST pan in the corresponding direction
- AND `+`/`-` keys MUST zoom in/out

#### Scenario MAP-04b: Screen reader support

- GIVEN a screen reader is active
- WHEN the map component is rendered
- THEN the map container MUST have `role="application"` and `aria-label="Kaart met zaaklocaties"`
- AND each marker MUST have an accessible label with the case title

---

### REQ-005: GisProxyController SHALL expose `proxy` and `capabilities` action endpoints for external GIS services

`OCA\Procest\Controller\GisProxyController::proxy()` SHALL accept `url`, `query`, and `type` parameters and forward the request to `GisProxyService::proxyRequest()`. `GisProxyController::capabilities()` SHALL accept `url` and `type` parameters and delegate to `GisProxyService::getCapabilities()`. Both endpoints SHALL be authenticated (`#[NoAdminRequired]`) and SHALL return JSON error envelopes — HTTP 400 when the required `url` parameter is missing, HTTP 403 when the upstream allowlist rejects the URL, HTTP 429 when rate-limited, and HTTP 502 for any other upstream failure.

#### Scenario: Missing url returns 400
- **WHEN** a caller invokes `proxy()` or `capabilities()` without a `url` parameter
- **THEN** the controller SHALL return a JSON response with status 400 and body `{"error": "Missing required parameter: url"}`

#### Scenario: Disallowed URL maps to 403
- **GIVEN** a `url` whose host is not in the configured `wmsLayer` allowlist
- **WHEN** `proxy()` is invoked
- **THEN** the service raises `\RuntimeException` with code 403
- **AND** the controller SHALL return a JSON response with status 403 and body `{"error": "URL not allowed: <message>"}`

#### Scenario: Rate-limited upstream maps to 429
- **GIVEN** the current user has already issued 100 proxied requests in the current minute
- **WHEN** `proxy()` is invoked
- **THEN** the controller SHALL return a JSON response with status 429 and body `{"error": "Rate limit exceeded"}`

#### Scenario: Generic upstream failure maps to 502
- **GIVEN** the proxied request fails for any reason other than 403/429
- **WHEN** `proxy()` is invoked
- **THEN** the controller SHALL return a JSON response with status 502 and body `{"error": "Proxy request failed: <message>"}`

---

### REQ-006: GisProxyService SHALL enforce a host-based allowlist, per-user rate limiting, and 5-minute response caching

`OCA\Procest\Service\GisProxyService` SHALL provide `proxyRequest()`, `getCapabilities()`, host-based allowlist validation (`isUrlAllowed()`), and per-user-per-minute rate limiting (`checkRateLimit()`). The allowlist SHALL unconditionally accept hosts containing `pdok.nl` or `kadaster.nl`, and otherwise SHALL compare the request host to the parsed host of every configured `wmsLayer` object in the configured register/schema. Successful responses SHALL be cached in the distributed cache `procest_gis_proxy` keyed by `md5(fullUrl)` for 300 seconds. The per-user rate limit SHALL allow at most 100 requests per minute (key: `rate_limit_<uid>_<YmdHi>`); exceeding the limit SHALL raise `\RuntimeException` with code 429.

#### Scenario: PDOK and Kadaster hosts are always allowed
- **GIVEN** a request URL with host `service.pdok.nl` or any subdomain of `kadaster.nl`
- **WHEN** `isUrlAllowed()` is evaluated
- **THEN** the method SHALL return `true` without consulting OpenRegister

#### Scenario: Non-PDOK host requires a matching wmsLayer record
- **GIVEN** a request URL with host `example.org`
- **AND** no `wmsLayer` record exists whose `url` parses to that host
- **WHEN** `proxyRequest()` is invoked
- **THEN** the service SHALL throw `\RuntimeException('URL not in configured layer allowlist', 403)`

#### Scenario: Cached response is returned without re-fetching
- **GIVEN** `proxyRequest($url, $query, $type)` was called once and the response cached
- **WHEN** the same `(url, query)` pair is requested again within 300 seconds
- **THEN** the service SHALL return the cached array without issuing a new HTTP request

#### Scenario: Rate-limit overflow raises 429
- **GIVEN** the current user's counter `rate_limit_<uid>_<YmdHi>` is already at 100
- **WHEN** `proxyRequest()` is invoked
- **THEN** the service SHALL throw `\RuntimeException('Rate limit exceeded', 429)`
- **AND** SHALL log a `warning` event with the userId and current count

#### Notes
- The HTTP forwarder uses `file_get_contents()` with a stream context — a TODO for future hardening is to migrate to Guzzle via `IClient` so timeouts/headers/redirects follow the rest of Procest.
- XML responses are converted to associative arrays via `simplexml_load_string` + JSON round-trip; behavior on malformed XML is "return raw string", which is observed but undocumented.
