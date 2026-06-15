# case-map-overview Specification

## MODIFIED Requirements

### Requirement: Cases Map View

The system SHALL provide a multi-object overview map of all cases that carry
geometry, accessible from the main navigation, rendered from OpenRegister's
page-level **maps-overview** integration surface (ADR-022, openregister PR #154).
Procest SHALL declare a `cases-on-map` overview for its case register/schema via
`POST /apps/openregister/api/integrations/maps/overviews` and SHALL fetch the
marker set from `GET /apps/openregister/api/integrations/maps/overviews/{register}/{schema}/points`,
which returns the RBAC-scoped points (`{ points: [{ id, label, lat, lng,
register, schema, geometry }], count }`). Procest SHALL NOT run a bespoke geo
query, a bespoke per-object access guard, or a bespoke Leaflet/WMS/WFS stack; the
markers SHALL render through `@conduction/nextcloud-vue`'s declarative
`CnMapWidget` (which owns the Leaflet engine, clustering, and the base-layer
tiles supplied by OR's declarative base-layer config).

@e2e exclude The marker rendering (tiles, clustering, base layer) is owned by `@conduction/nextcloud-vue`'s `CnMapWidget` (cross-library) and the RBAC-scoped point query + geometry extraction by OpenRegister's maps-overview surface (cross-app, OR #154); the procest-side contract is the thin maps-overview client (`casesOnMapApi.js` — endpoint URLs + `{ points }` unwrapping + fail-closed degradation) and the pure marker shaping (`shapeMarkerFeatures`), both covered by vitest, plus the removal of the bespoke Leaflet/WMS/WFS stack (a static no-parallel-UI check). Without the OR maps-overview surface installed the markers cannot be exercised by a procest-only UI e2e.

#### Scenario: Display all RBAC-visible cases on the overview map

- GIVEN cases with geometry that the current user may read
- WHEN the user navigates to "Map" in the Procest navigation
- THEN the `/map` page SHALL declare the `cases-on-map` overview with OpenRegister
- AND it SHALL fetch the RBAC-scoped points from OR's maps-overview points endpoint
- AND each point SHALL render as a marker through `CnMapWidget` with clustering active
- AND a case the user may NOT read SHALL NOT appear as a marker (OR scopes the query fail-closed)

#### Scenario: Case marker click navigates to the case detail

- GIVEN cases are displayed on the overview map
- WHEN the user clicks a case marker
- THEN the view SHALL navigate to the case detail for that case's id

#### Scenario: Status-based marker styling

- GIVEN cases with different statuses
- WHEN shaped for the map by `shapeMarkerFeatures`
- THEN each marker SHALL carry a status-derived colour token (no hardcoded hex)
- AND markers without finite coordinates SHALL be skipped rather than throw

### Requirement: Map Filters

The overview map SHALL support filtering cases by case type and status, applied
by forwarding the selected values to OpenRegister's maps-overview points
endpoint as object filters (OR re-runs the RBAC-scoped query) — not by a
bespoke in-app spatial/attribute filter engine.

@e2e exclude Filter application is a forward of the selected case-type / status values into OR's points-endpoint query params; the resulting re-query + RBAC scoping is owned by OpenRegister (cross-app, OR #154), and the procest-side forwarding is covered by the `fetchCasePoints` vitest (asserts the filters reach `axios.get` params). Without the OR maps-overview surface installed it cannot be exercised by a procest-only UI e2e.

#### Scenario: Filter by case type and status

- GIVEN the overview map showing the readable cases
- WHEN the user selects a case type and/or a status filter
- THEN those values SHALL be forwarded to OR's points endpoint as object filters
- AND the map SHALL re-render the markers OR returns for the filtered, RBAC-scoped set
