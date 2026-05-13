<!-- Delta spec for change `wms-wfs-layers`.
     Parent capability: map-component (Map Component) — see openspec/specs/map-component/spec.md
     This delta narrows the WMS/WFS slice of map-component's MapLayer model into a dedicated
     `wmsLayer` schema with per-case-type subscriptions. It complements (does not replace)
     the existing `wms-wfs-layers` canonical spec, which describes admin CRUD, proxying,
     and capabilities parsing. -->

## ADDED Requirements

### Requirement: wmsLayer schema (REQ-WMS-1)

The system SHALL define a `wmsLayer` schema in `lib/Settings/procest_register.json` with the WMS/WFS-specific fields needed to render an overlay: `title`, `type` (enum `WMS` / `WFS`), `url`, `layerName`, `srs`, `opacity` (0.0–1.0), `attribution`, `queryable` (boolean), `format`, `version`, and `isDefault` (boolean).

**Feature tier**: V1
**Standards**: OGC WMS 1.3.0, OGC WFS 2.0.0

#### Scenario: Create a WMS layer object

- **GIVEN** an admin POSTs `{ title: "Kadastrale kaart", type: "WMS", url: "https://service.pdok.nl/kadaster/kadastralekaart/wms/v5_0", layerName: "Perceel", srs: "EPSG:28992", opacity: 0.6, attribution: "PDOK", queryable: true }` to the wmsLayer collection
- **WHEN** OpenRegister persists the object
- **THEN** the response SHALL include a UUID `id`
- **AND** GET on that id SHALL return the same field values
- **AND** omitting `opacity` SHALL default it to `0.7`
- **AND** omitting `queryable` SHALL default it to `false`

---

### Requirement: Manifest-driven layer admin page (REQ-WMS-2)

The `wmsLayer` admin SHALL be declared as a `type: 'index'` page in `src/manifest.json` (per ADR-008). No hand-written admin view component SHALL exist for `wmsLayer`.

**Feature tier**: V1
**Standards**: ADR-008 (manifest-driven views)

#### Scenario: Manifest declares index page on wmsLayer

- **GIVEN** `src/manifest.json` defines a page with `route: "/settings/wms-layers"`
- **WHEN** the manifest is loaded by the app shell
- **THEN** the page entry SHALL have `"type": "index"`
- **AND** the page entry SHALL NOT have a `"component"` key
- **AND** the page `config` SHALL declare `register: "procest"` and `schema: "wmsLayer"`
- **AND** no file `src/views/settings/WmsLayerAdmin.vue` SHALL exist in the repository

---

### Requirement: WmsWfsService client (REQ-WMS-3)

The system SHALL provide a `WmsWfsService` PHP class that issues GetCapabilities, GetMap and GetFeature requests **only through the existing GIS proxy** declared in the parent `wms-wfs-layers` canonical spec (REQ-LAYER-03). Direct outbound HTTP from `WmsWfsService` SHALL be forbidden.

**Feature tier**: V1
**Standards**: OGC WMS 1.3.0, OGC WFS 2.0.0

#### Scenario: Test connection parses GetCapabilities

- **GIVEN** an admin clicks "Verbinding testen" on a draft `wmsLayer` with `url: "https://service.pdok.nl/lv/bag/wms/v2_0"` and `type: "WMS"`
- **WHEN** `WmsWfsService::testConnection(url, type)` is invoked
- **THEN** the service SHALL issue `GET {proxy}?REQUEST=GetCapabilities&SERVICE=WMS&url=...`
- **AND** the response SHALL include `{ ok: true, layers: [...] }` parsed from the GetCapabilities XML
- **AND** parsed capabilities SHALL be cached for 1 hour

#### Scenario: URL outside allowlist is rejected

- **GIVEN** an admin attempts to save a `wmsLayer` with `url: "https://evil.example.com/wms"` not present in the GIS proxy allowlist
- **WHEN** `WmsWfsService` validates the URL prior to any outbound call
- **THEN** the save SHALL return HTTP 400 with error code `wms.url_not_allowed`
- **AND** no outbound request SHALL be issued

---

### Requirement: Case-type → layer subscription (REQ-WMS-4)

The `caseType` schema SHALL be extended with a `layerIds: array<string>` field referencing `wmsLayer` UUIDs. The case-type admin SHALL expose a "Kaartlagen" tab where admins multi-select layers for that case type.

**Feature tier**: V1
**Standards**: Schema.org `additionalProperty`

#### Scenario: Subscribe layers to a case type

- **GIVEN** a `caseType` "Omgevingsvergunning" and two `wmsLayer` objects `L1` (Kadaster) and `L2` (Bestemmingsplan)
- **WHEN** an admin selects both layers on the case type's "Kaartlagen" tab and saves
- **THEN** `caseType.layerIds` SHALL equal `[L1.id, L2.id]`
- **AND** GET on the case type SHALL return the same array

#### Scenario: Subscription persists across reload

- **GIVEN** `caseType.layerIds = [L1, L2]`
- **WHEN** the case-type detail page is reloaded
- **THEN** the "Kaartlagen" tab SHALL show both `L1` and `L2` as selected
- **AND** other `wmsLayer` objects SHALL appear as unselected options

---

### Requirement: Layer prop on CaseMap (REQ-WMS-5)

`CaseMap` (and its `CnMapPage` wrapper used by the case overview) SHALL accept a `layerIds: string[]` prop. On mount and on prop change, it SHALL resolve the ids to `wmsLayer` objects, additionally include any `wmsLayer` with `isDefault: true`, and instantiate Leaflet WMS / WFS layers via `WmsWfsService`.

**Feature tier**: V1
**Standards**: GeoJSON (RFC 7946), Leaflet 1.9

#### Scenario: Lazy load — toggled-off layer issues no requests

- **GIVEN** a `CaseMap` mounted with `layerIds: [L1, L2]` where both layers are initially toggled off in the legend
- **WHEN** the map renders
- **THEN** no tile or feature requests SHALL be issued for `L1` or `L2`
- **AND** toggling `L1` on SHALL trigger exactly one GetMap (WMS) or GetFeature (WFS) sequence
- **AND** toggling `L1` off SHALL detach the Leaflet layer and cancel in-flight requests

#### Scenario: Tile size cap

- **GIVEN** a WMS layer `L1` rendered on a 2048×2048 map viewport
- **WHEN** `WmsWfsService::buildGetMapUrl` constructs tile URLs
- **THEN** each individual GetMap request SHALL have `WIDTH ≤ 512` and `HEIGHT ≤ 512`
- **AND** Leaflet SHALL tile the viewport into multiple requests rather than issuing one oversize request

---

### Requirement: Legend renderer (REQ-WMS-6)

A `MapLayerLegend` component SHALL render one row per resolved layer with: checkbox (toggle), title, opacity slider (0–100, default from `wmsLayer.opacity × 100`), and attribution line. Attribution SHALL be rendered as escaped text.

**Feature tier**: V1
**Standards**: WCAG AA

#### Scenario: Opacity slider updates layer opacity

- **GIVEN** layer `L1` is toggled on with default opacity `0.7`
- **WHEN** the user moves the opacity slider to `30`
- **THEN** the Leaflet layer's opacity SHALL update to `0.3` in real time
- **AND** the slider value SHALL persist in component state for the session

#### Scenario: Attribution is escaped

- **GIVEN** a `wmsLayer` with `attribution: "<script>alert(1)</script>"`
- **WHEN** the legend renders that layer's row
- **THEN** the DOM SHALL contain the literal text `<script>alert(1)</script>`
- **AND** no `<script>` element SHALL be present in the legend subtree

---

### Requirement: Queryable layers and GetFeatureInfo (REQ-WMS-7)

GetFeatureInfo (WMS) and feature popup (WFS) SHALL only be wired up when `wmsLayer.queryable === true`. Non-queryable layers SHALL render overlay-only with no click handler.

**Feature tier**: V1
**Standards**: OGC WMS 1.3.0 (GetFeatureInfo)

#### Scenario: Queryable WMS layer shows popup on click

- **GIVEN** a WMS layer `L1` with `queryable: true` displayed on the map
- **WHEN** the user clicks a point on the overlay
- **THEN** the system SHALL issue `GetFeatureInfo` via the GIS proxy
- **AND** a popup SHALL display the returned feature attributes

#### Scenario: Non-queryable layer ignores clicks

- **GIVEN** a WMS layer `L2` with `queryable: false`
- **WHEN** the user clicks the overlay
- **THEN** no `GetFeatureInfo` request SHALL be issued
- **AND** no popup SHALL be displayed
- **AND** the click SHALL fall through to underlying layers (e.g. case marker popups)

---

### Requirement: WFS extent guard (REQ-WMS-8)

WFS GetFeature requests SHALL always carry a `BBOX` parameter restricted to the visible map extent. When the visible extent exceeds 50 km × 50 km, the WFS layer SHALL be dimmed and a "Zoom in voor details" overlay message SHALL be displayed instead of fetching features.

**Feature tier**: V1
**Standards**: OGC WFS 2.0.0

#### Scenario: WFS request scoped to viewport

- **GIVEN** a WFS layer `L3` toggled on at zoom level 14 with extent ~2 km × 2 km
- **WHEN** `WmsWfsService::buildGetFeatureUrl` constructs the URL
- **THEN** the URL SHALL include a `BBOX` parameter matching the visible extent
- **AND** the request SHALL be issued through the GIS proxy

#### Scenario: WFS suppressed at low zoom

- **GIVEN** a WFS layer `L3` toggled on at zoom level 7 (national view, extent ~300 km × 300 km)
- **WHEN** the map computes the visible extent (> 50 km × 50 km)
- **THEN** no WFS GetFeature request SHALL be issued
- **AND** the legend row for `L3` SHALL display "Zoom in voor details" in Dutch
- **AND** the overlay SHALL be visually dimmed (opacity halved)
