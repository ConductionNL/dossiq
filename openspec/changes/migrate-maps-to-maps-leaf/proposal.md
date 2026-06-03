# Proposal: migrate-maps-to-maps-leaf

## Why

Procest ships an entire in-app geographic stack: a Leaflet-based Vue map (`src/components/map/*.vue`
— `MapComponent`, `CaseMap`, `LocationPicker`, `AddressSearch`, `MapLayerSwitcher`, `MapLegend`,
`SpatialFilter`, `CasePopup`), PDOK base-tile + geocoding services (`lib/Service/Pdok/*`), a
WMS/WFS layer client (`WmsWfsService`, `WfsExportService`), and a `LocationService`. The
consumer-facing surface lives in specs `map-component`, `pdok-integration`, `wms-wfs-layers`,
`case-map-overview`, and `case-location`.

OpenRegister now provides a **maps** integration leaf (ADR-019 integration registry). The leaf
renders an object's geo data as a map tab + widget on any OR-backed detail page, with base-tile,
layer-switching, and marker interaction handled by the leaf — not the consuming app. Maintaining a
parallel Leaflet/WMS/WFS stack in procest is a direct **ADR-022** violation (Apps Consume
OpenRegister Abstractions). Concrete harms:

- **Duplicate map UI**: procest re-implements pan/zoom/cluster/layer-switch that the maps leaf
  already provides and tests; every other Conduction app that needs a map re-solves the same.
- **Drift risk**: WMS/WFS layer config, tile sources, and marker styling evolve independently of
  the fleet's shared map.
- **Orphaned code**: eight map Vue components plus `WmsWfsService`/`WfsExportService`/`LocationService`
  are maintenance surface with no architectural benefit once the leaf renders the map.
- **PDOK already centralised**: address resolution is being moved into openconnector by the Hydra
  umbrella `shared-pdok-via-openconnector` (procest-side: `migrate-pdok-to-openconnector`). The map
  UI is the remaining in-app duplication.

## What

This change replaces procest's in-app map **UI** with the OR maps leaf on the `case` detail page,
while keeping case-location **data** (the geo-typed field on the case schema) in procest's register:

1. The `case` detail page renders the OR maps leaf tab/widget instead of `CaseMap.vue`. The case's
   geo field supplies the marker; the leaf owns tiles, layers, zoom, and interaction.
2. The eight `src/components/map/*.vue` components are removed once the leaf is wired in.
3. `WmsWfsService`, `WfsExportService`, and `LocationService` are removed; layer rendering is the
   leaf's responsibility. (PDOK geocoding is handled by `migrate-pdok-to-openconnector`, not here.)
4. The `case` schema retains its `location` geo property — the geo *data* contract is unchanged;
   only the *rendering* moves to the leaf.

The existing procest specs (`case-location`) remain as the data contract. The map-rendering specs
(`map-component`, `wms-wfs-layers`, `case-map-overview`) are superseded by the leaf and marked for
sunset; `pdok-integration` is owned by the PDOK migration.

## Capabilities

### New Capabilities

- `case-map-via-maps-leaf`: The case detail page renders its location through OR's maps integration
  leaf instead of an in-app Leaflet component; procest stores only the geo field and registers no
  map UI of its own.

### Modified Capabilities

- `case-location` (spec: `procest/openspec/specs/case-location/spec.md`) — the geo *data* contract
  is unchanged; the spec note clarifies that rendering is delegated to the maps leaf, not an in-app
  component.

## Affected Projects

- [x] Project: `procest` — all implementation tasks are in this repo
- [x] Project: `openregister` — no code change; the maps leaf is consumed, not modified

## Out of Scope

- PDOK geocoding/address resolution — owned by `migrate-pdok-to-openconnector` and the Hydra
  umbrella `shared-pdok-via-openconnector`. This change does NOT duplicate that work.
- The maps leaf's own implementation in OR.
- Backfill/migration of existing case `location` data (the geo field shape is unchanged).
- The case-overview clustered map across many cases — if the maps leaf cannot render a
  multi-object overview surface, that gap is flagged in design.md (possible follow-up), not
  re-implemented in-app.

## Success Criteria

- `openspec validate migrate-maps-to-maps-leaf --strict` exits 0.
- The case detail page shows the maps leaf tab/widget driven by the case `location` field.
- `src/components/map/*.vue`, `WmsWfsService`, `WfsExportService`, `LocationService` are removed.
