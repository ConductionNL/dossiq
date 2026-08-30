# case-map-via-maps-leaf Specification

## MODIFIED Requirements

### Requirement: Geo Data Contract Stays In Procest's Register

The `case` schema SHALL retain its geometry-typed property as the single source of truth for case coordinates, and procest SHALL NOT introduce a parallel location store or a bespoke geo-query endpoint. Both map surfaces read this property: OR's per-object `maps` leaf renders it on the case detail, and OR's page-level maps-overview surface extracts a representative point per object for the multi-object `/map` overview. The bespoke multi-object Leaflet stack (`src/components/map/*`) and the WMS/WFS service classes (`WmsWfsService` / `WfsService` / `WfsExportService` / `GeoService` / `LocationService` / `MapLayerService` / `GisProxyService`) — deferred by `migrate-maps-to-maps-leaf` pending a page-level OR surface (issue #112) — SHALL be removed now that OpenRegister ships that surface (PR #154).

@e2e exclude The geometry property declaration is an OpenRegister schema/config concern verified by schema validation, and the removal of the bespoke per-case `LocationTab.vue` + the multi-object Leaflet/WMS/WFS stack is a static codebase check — neither is a procest-only browser UI surface. Editing geometry and seeing the marker update is driven by OR's object form + the cross-app maps leaf / maps-overview surface, which a procest-only e2e cannot mount without OR installed.

#### Scenario: Geo field remains a first-class case property

- **GIVEN** the `case` schema after this migration
- **WHEN** the schema is inspected
- **THEN** the geometry property SHALL still be present and geo-typed (GeoJSON)
- **AND** editing it through OR's object form SHALL update both the per-case leaf marker and the overview marker

#### Scenario: The bespoke multi-object map stack is removed

- **GIVEN** the procest codebase after this migration
- **WHEN** `src/components/map/` and `lib/Service/` are inspected
- **THEN** the bespoke `CaseMap.vue` / Leaflet components SHALL NOT be present
- **AND** `WmsWfsService` / `WfsService` / `WfsExportService` / `GeoService` / `LocationService` / `MapLayerService` / `GisProxyService` SHALL NOT be present
- **AND** the `/map` page SHALL render through OpenRegister's maps-overview surface instead

### Requirement: WMS/WFS And Layer Rendering Are Delegated To The Leaf

The OpenRegister map surfaces SHALL own WMS/WFS background layers, layer switching, base-layer configuration, and legend rendering — both the per-object `maps` leaf and the page-level maps-overview surface, whose base-layer config is declarative (PDOK WMTS by default, overridable). Procest SHALL NOT mount an in-app layer-switcher component, manage WMS/WFS overlay-layer CRUD, or issue any direct WMS/WFS HTTP request from its own service classes on either map surface.

@e2e exclude Layer switching / WMS/WFS background rendering and the base-layer config are owned by the OR map surfaces + `CnMapWidget` (cross-app/cross-library, ADR-019 / OR #154); the procest-side assertion ("procest issues no direct WMS/WFS request and ships no WMS/WFS service classes") is a static no-direct-call / removed-file check covered by code review, not a procest-only browser UI surface drivable without OR installed.

#### Scenario: Layer + base-layer config come from OpenRegister

- **GIVEN** either OR map surface rendering procest cases
- **WHEN** the user interacts with map layers
- **THEN** layer switching and the base-layer tiles SHALL be provided by OR / `CnMapWidget`
- **AND** procest SHALL ship no WMS/WFS service class and issue no direct WMS/WFS HTTP request
