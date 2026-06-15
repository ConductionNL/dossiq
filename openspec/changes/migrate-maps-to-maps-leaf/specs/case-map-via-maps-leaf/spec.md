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

#### Scenario: The bespoke per-case map surface is removed

- **GIVEN** the procest codebase after this migration
- **WHEN** `src/views/cases/components/` is inspected
- **THEN** the bespoke per-case `LocationTab.vue` (single-case Leaflet surface) SHALL NOT be present
- **AND** the case detail SHALL surface the maps leaf tab instead

> NOTE (scope): Removing `WmsWfsService` / `WfsExportService` /
> `LocationService` and the `src/components/map/*.vue` stack is DEFERRED. Those
> back the **multi-object** cases-on-map overview (`CasesOnMapView`, the `/map`
> page), which the per-object maps leaf cannot yet render (it returns lat/lng
> rows for one object). That removal is blocked on a page-level maps-overview
> surface in OR — tracked as Codeberg procest issue #112.

---

### Requirement: WMS/WFS And Layer Rendering Are Delegated To The Leaf

On the per-case map surface, WMS/WFS background layers, layer switching, and legend rendering SHALL
be the maps leaf's responsibility; the per-case detail SHALL NOT mount an in-app layer-switcher
component. (Procest's WMS/WFS service classes remain in place for the multi-object overview until
issue #112 lands — they are not invoked by the per-case maps-leaf tab.)

#### Scenario: Layer controls come from the leaf

- **GIVEN** the maps leaf rendered on a case detail page
- **WHEN** the user interacts with map layers
- **THEN** layer switching SHALL be provided by the leaf
- **AND** procest SHALL issue no direct WMS/WFS HTTP request from its own service classes
