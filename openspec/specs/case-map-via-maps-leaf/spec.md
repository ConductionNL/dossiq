# case-map-via-maps-leaf Specification

## Purpose
TBD - created by archiving change migrate-maps-to-maps-leaf. Update Purpose after archive.
## Requirements
### Requirement: Case Detail Renders Its Location Through The OR Maps Leaf

The case detail page SHALL render the case's geographic location using OpenRegister's `maps`
integration leaf (ADR-019) instead of an in-app Leaflet component. Procest SHALL NOT register
its own map UI, tile source, or layer switcher.

@e2e exclude Map rendering (tiles, marker placement, empty/no-location state) is owned by OpenRegister's `maps` integration leaf (cross-app, ADR-019); the leaf tab surfaces via `@conduction/nextcloud-vue`'s builtin integration registry and fetches from the OR integrations endpoint. Without the OR maps leaf installed it cannot be exercised by a procest-only UI e2e. The procest-side change is the removal of the bespoke `CaseMap.vue`/Leaflet surface (a static no-parallel-UI check) plus the registry/manifest tab wiring (gate-22 manifest validation), not a procest UI surface.

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

@e2e exclude The `location` geo property declaration is an OpenRegister schema/config concern verified by schema validation + PHPUnit (`caseGeoService`), and the removal of the bespoke per-case `LocationTab.vue` is a static codebase check — neither is a procest-only browser UI surface. Editing `location` and seeing the marker update is driven by OR's object form + the cross-app maps leaf, which a procest-only e2e cannot mount without the OR maps leaf installed.

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

> NOTE (scope): RESOLVED (issue #112, change `migrate-cases-on-map-to-maps-overview-leaf`).
> The **multi-object** cases-on-map overview (`CasesOnMapView`, the `/map` page)
> now consumes OpenRegister's page-level **maps-overview** surface (openregister
> PR #154) — RBAC-scoped marker points + a declarative base layer, rendered
> through the library's `CnMapWidget`. The bespoke Leaflet stack
> (`src/components/map/*`) and the WMS/WFS service classes (`WmsWfsService` /
> `WfsService` / `WfsExportService` / `GeoService` / `LocationService` /
> `MapLayerService` / `GisProxyService`) have been removed.

---

### Requirement: WMS/WFS And Layer Rendering Are Delegated To The Leaf

On the per-case map surface, WMS/WFS background layers, layer switching, and legend rendering SHALL
be the maps leaf's responsibility; the per-case detail SHALL NOT mount an in-app layer-switcher
component. (Procest's WMS/WFS service classes remain in place for the multi-object overview until
issue #112 lands — they are not invoked by the per-case maps-leaf tab.)

@e2e exclude Layer switching / WMS/WFS background rendering on the per-case surface is owned by the OR maps leaf (cross-app, ADR-019); the procest-side assertion ("procest issues no direct WMS/WFS HTTP request from its own service classes" on the per-case tab) is a static no-direct-call check covered by code review + PHPUnit, not a procest-only browser UI surface drivable without the OR maps leaf installed.

#### Scenario: Layer controls come from the leaf

- **GIVEN** the maps leaf rendered on a case detail page
- **WHEN** the user interacts with map layers
- **THEN** layer switching SHALL be provided by the leaf
- **AND** procest SHALL issue no direct WMS/WFS HTTP request from its own service classes

