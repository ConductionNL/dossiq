---
retrofit_extensions:
  - REQ-001
  - REQ-002
---

# WMS/WFS Layers — proxy controller + layer service (retrofit)

## Requirements

### REQ-001: WmsWfsController SHALL expose a per-layer WMS/WFS proxy action endpoint

`OCA\Procest\Controller\WmsWfsController::proxy()` SHALL accept a `layerId` query parameter, resolve the corresponding `wmsLayer` object via `WmsWfsService::getLayerById()`, and forward all other request parameters to `WmsWfsService::proxyRequest()`. The endpoint SHALL be authenticated (`#[NoAdminRequired]`) and SHALL return JSON error envelopes for missing parameters (HTTP 400), unknown layers (HTTP 404), and upstream failures (HTTP 4xx/5xx, defaulting to 502 when the upstream exception code is out of range).

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

`OCA\Procest\Service\WmsWfsService` SHALL provide `getLayersForCaseType()`, `validateLayer()`, `proxyRequest()`, `buildGetMapUrl()`, `buildGetFeatureUrl()`, and `getLayerById()`. The service SHALL NEVER issue direct outbound HTTP — every external request SHALL be delegated to `GisProxyService::proxyRequest()` so the GIS proxy allowlist (REQ-LAYER-03c) and rate limiting (REQ-LAYER-03d) remain authoritative. Layer resolution per case type SHALL include all `wmsLayer` UUIDs listed in `caseType.layerIds` plus all layers with `isDefault: true`, with `active: false` layers filtered out. GetMap tile dimensions SHALL be capped at 512 pixels.

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
