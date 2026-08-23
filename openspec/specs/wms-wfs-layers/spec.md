---
status: done
retrofit_extensions:
  - REQ-001
  - REQ-002
---

# WMS/WFS Layer Support Specification

## Purpose

@e2e exclude WMS/WFS layer configuration is V1; GIS proxy and map layer scenarios require external OGC services not available in CI.

Enable administrators to configure external WMS (Web Map Service) and WFS (Web Feature Service) layers that overlay on the case map. This allows municipalities to display their own geodata (bestemmingsplannen, kadastrale grenzen, milieucontouren) alongside case locations. A backend GIS proxy handles CORS restrictions for external services.

**Standards**: OGC WMS 1.3.0, OGC WFS 2.0.0, PDOK services, INSPIRE
**Feature tier**: V1

## Requirements

---

### REQ-LAYER-01: Admin Layer Configuration

Administrators MUST be able to configure WMS and WFS layers in the Dossiq admin settings.

**Feature tier**: V1

#### Scenario LAYER-01a: Add WMS layer

- GIVEN the admin navigates to Dossiq > Instellingen > Kaartlagen
- WHEN the admin clicks "Kaartlaag toevoegen"
- THEN a form MUST appear with fields: title, type (WMS/WFS/tile/GeoJSON), URL, layer name(s), format, attribution, default visibility, base layer toggle, opacity, min/max zoom
- AND saving MUST create a MapLayer object in OpenRegister

#### Scenario LAYER-01b: PDOK preset layers

- GIVEN the admin opens the layer configuration
- WHEN the admin clicks "PDOK laag toevoegen"
- THEN a list of common PDOK layers MUST be available:
  - Kadastrale kaart (WMS)
  - BAG (WMS)
  - Bestemmingsplannen / Ruimtelijkeplannen.nl (WMS)
  - CBS Wijken en buurten (WFS)
  - AHN3 hoogtekaart (WMS)
  - Natura 2000 (WMS)
- AND selecting a preset MUST auto-fill the URL, layer name, format, and attribution

#### Scenario LAYER-01c: Test layer connection

- GIVEN the admin has entered a WMS URL
- WHEN the admin clicks "Verbinding testen"
- THEN the system MUST send a GetCapabilities request (via proxy)
- AND display the available layer names from the capabilities response
- AND show a success/error indicator

#### Scenario LAYER-01d: Layer ordering

- GIVEN multiple configured layers
- WHEN the admin reorders layers via drag-and-drop
- THEN the display order MUST update for all map views

---

### REQ-LAYER-02: Layer Display on Maps

Configured layers MUST be available as overlays on all map views (case detail, overview, location picker).

**Feature tier**: V1

#### Scenario LAYER-02a: Toggle WMS overlay

- GIVEN the kadastrale kaart WMS layer is configured
- WHEN the user opens the layer switcher on any map view
- THEN "Kadastrale kaart" MUST appear in the overlay section
- AND toggling it on MUST display the WMS tiles overlaid on the base map
- AND toggling it off MUST hide the WMS tiles

#### Scenario LAYER-02b: WFS feature interaction

- GIVEN a CBS Wijken/buurten WFS layer is enabled
- WHEN the user clicks on a wijk polygon
- THEN a popup MUST show the feature properties (wijknaam, wijkcode, gemeente)
- AND the wijk boundary MUST be highlighted

#### Scenario LAYER-02c: Layer opacity control

- GIVEN a WMS overlay is visible
- WHEN the user adjusts the opacity slider in the layer switcher
- THEN the overlay opacity MUST update in real-time

---

### REQ-LAYER-03: GIS Proxy Endpoint

The backend MUST provide a proxy endpoint for WMS/WFS requests to handle CORS restrictions.

**Feature tier**: V1

#### Scenario LAYER-03a: Proxy WMS GetMap request

- GIVEN a WMS layer with `proxyEnabled: true`
- WHEN the frontend requests a map tile
- THEN the request MUST be routed through `POST /api/gis/proxy`
- AND the backend MUST forward the request to the configured WMS URL
- AND return the tile image with proper content-type headers
- AND the response MUST be cached for 5 minutes (configurable)

#### Scenario LAYER-03b: Proxy WFS GetFeature request

- GIVEN a WFS layer with `proxyEnabled: true`
- WHEN the frontend requests features within a bounding box
- THEN the request MUST be routed through the GIS proxy
- AND the backend MUST forward the WFS request with bbox filter
- AND return the GeoJSON/GML response

#### Scenario LAYER-03c: Proxy URL allowlist

- GIVEN the admin has configured layers with URLs from pdok.nl and geo.amsterdam.nl
- WHEN a proxy request arrives for a URL from evil.example.com
- THEN the proxy MUST reject the request with 403 Forbidden
- AND only URLs matching configured MapLayer objects are allowed

#### Scenario LAYER-03d: Proxy rate limiting

- GIVEN the GIS proxy is handling requests
- WHEN more than 100 requests per minute arrive from a single user
- THEN the proxy MUST return 429 Too Many Requests
- AND log the rate limit event

---

### REQ-LAYER-04: GetCapabilities Parser

The system MUST parse WMS/WFS GetCapabilities responses to assist administrators.

**Feature tier**: V1

#### Scenario LAYER-04a: Parse WMS capabilities

- GIVEN the admin enters `https://service.pdok.nl/lv/bag/wms/v2_0`
- WHEN the system fetches the GetCapabilities
- THEN the admin MUST see a list of available layers with titles
- AND selecting a layer MUST auto-fill the layer name field
- AND the supported CRS and formats MUST be extracted

#### Scenario LAYER-04b: Parse WFS capabilities

- GIVEN the admin enters a WFS endpoint URL
- WHEN the system fetches the GetCapabilities
- THEN the admin MUST see available feature types
- AND the default output format (GeoJSON if available) MUST be auto-selected

<!-- BEGIN retrofit-2026-05-24-wms-wfs-layers -->

## Proxy Controller + Layer Service (retrofit)

### REQ-001: WmsWfsController SHALL expose a per-layer WMS/WFS proxy action endpoint

`OCA\Dossiq\Controller\WmsWfsController::proxy()` SHALL accept a `layerId` query parameter, resolve the corresponding `wmsLayer` object via `WmsWfsService::getLayerById()`, and forward all other request parameters to `WmsWfsService::proxyRequest()`. The endpoint SHALL be authenticated (`#[NoAdminRequired]`) and SHALL return JSON error envelopes for missing parameters (HTTP 400), unknown layers (HTTP 404), and upstream failures (HTTP 4xx/5xx, defaulting to 502 when the upstream exception code is out of range).

#### Scenario: Missing layerId returns 400
- **WHEN** a caller invokes the proxy action without a `layerId` parameter
- **THEN** the controller SHALL return a JSON response with status 400 and body `{"error": "Missing required parameter: layerId"}`

#### Scenario: Unknown layer returns 404
- **GIVEN** a `layerId` that does not match any configured `wmsLayer` object
- **WHEN** the proxy action is invoked
- **THEN** the controller SHALL return a JSON response with status 404 and body `{"error": "Layer not found"}`

#### Scenario: Upstream RuntimeException maps to HTTP status
- **GIVEN** a valid `layerId` whose proxied request raises `\RuntimeException` with code 503
- **WHEN** the proxy action is invoked
- **THEN** the controller SHALL return a JSON response with the exception's code (or 502 if the code is outside 400-599) and body `{"error": "<message>"}`

### REQ-002: WmsWfsService SHALL resolve, validate, and proxy layers without direct outbound HTTP

`OCA\Dossiq\Service\WmsWfsService` SHALL provide `getLayersForCaseType()`, `validateLayer()`, `proxyRequest()`, `buildGetMapUrl()`, `buildGetFeatureUrl()`, and `getLayerById()`. The service SHALL NEVER issue direct outbound HTTP — every external request SHALL be delegated to `GisProxyService::proxyRequest()` so the GIS proxy allowlist (REQ-LAYER-03c) and rate limiting (REQ-LAYER-03d) remain authoritative. Layer resolution per case type SHALL include all `wmsLayer` UUIDs listed in `caseType.layerIds` plus all layers with `isDefault: true`, with `active: false` layers filtered out. GetMap tile dimensions SHALL be capped at 512 pixels.

#### Scenario: Layer resolution merges subscribed + default layers
- **GIVEN** a case type with `layerIds: ["uuid-a"]` and three configured layers — uuid-a (active), uuid-b (active, isDefault=true), uuid-c (inactive, isDefault=true)
- **WHEN** `WmsWfsService::getLayersForCaseType($caseType)` is called
- **THEN** the result SHALL contain layers uuid-a and uuid-b only

#### Scenario: All outbound traffic flows through GisProxyService
- **WHEN** `WmsWfsService::proxyRequest($layer, $params)` is invoked for any layer
- **THEN** the call SHALL delegate to `GisProxyService::proxyRequest()` and SHALL NOT open a direct HTTP connection from `WmsWfsService` itself

#### Scenario: Tile dimensions are capped at 512px
- **GIVEN** a caller requests `width=1024 height=1024` against `buildGetMapUrl()`
- **WHEN** the URL is constructed
- **THEN** the WIDTH and HEIGHT parameters SHALL be clamped to 512

<!-- END retrofit-2026-05-24-wms-wfs-layers -->
