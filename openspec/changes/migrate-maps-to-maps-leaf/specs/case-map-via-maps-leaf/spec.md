# case-map-via-maps-leaf Specification

## ADDED Requirements

### Requirement: Case Detail Renders Its Location Through The OR Maps Leaf

The case detail page SHALL render the case's geographic location using OpenRegister's `maps`
integration leaf (ADR-019) instead of an in-app Leaflet component. Procest SHALL NOT register
its own map UI, tile source, or layer switcher.

#### Scenario: Case with a location shows the maps leaf tab

- **GIVEN** a `case` object whose `location` geo property contains a valid GeoJSON Point
- **AND** the OR maps leaf is enabled and whitelisted on the `case` schema `configuration.linkedTypes`
- **WHEN** a handler opens the case detail page
- **THEN** the maps leaf tab SHALL be present in the object sidebar
- **AND** the leaf SHALL place a marker at the coordinates from the case `location` property
- **AND** no in-app `CaseMap.vue` / Leaflet component SHALL be mounted

#### Scenario: Case without a location degrades gracefully

- **GIVEN** a `case` object whose `location` property is empty
- **WHEN** a handler opens the case detail page
- **THEN** the maps leaf SHALL render its empty/no-location state
- **AND** the page SHALL NOT error

---

### Requirement: Geo Data Contract Stays In Procest's Register

The `case` schema SHALL retain its `location` geo-typed property as the single source of truth
for case coordinates. The maps leaf SHALL read this property; procest SHALL NOT introduce a
parallel location store or write map-UI state to its register.

#### Scenario: Geo field remains a first-class case property

- **GIVEN** the `case` schema after this migration
- **WHEN** the schema is inspected
- **THEN** the `location` property SHALL still be present and `geo`-typed (GeoJSON)
- **AND** editing the case `location` through OR's object form SHALL update the marker the leaf renders

#### Scenario: In-app geo services are removed

- **GIVEN** the procest codebase after this migration
- **WHEN** `lib/Service/` is inspected
- **THEN** `WmsWfsService`, `WfsExportService`, and `LocationService` SHALL NOT be present
- **AND** `src/components/map/` SHALL NOT contain in-app map rendering components

---

### Requirement: WMS/WFS And Layer Rendering Are Delegated To The Leaf

WMS/WFS background layers, layer switching, and legend rendering SHALL be the maps leaf's
responsibility. Procest SHALL NOT ship a WMS/WFS client or a layer-switcher component.

#### Scenario: Layer controls come from the leaf

- **GIVEN** the maps leaf rendered on a case detail page
- **WHEN** the user interacts with map layers
- **THEN** layer switching SHALL be provided by the leaf
- **AND** procest SHALL issue no direct WMS/WFS HTTP request from its own service classes
