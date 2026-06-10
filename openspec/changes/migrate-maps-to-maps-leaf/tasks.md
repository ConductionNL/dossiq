# Tasks: migrate-maps-to-maps-leaf

All tasks are in the `procest` repo. Estimates: S = half-day, M = 1–2 days, L = 3+ days.
Implementation runs through Hydra; this change is specs-only.

## [procest] Pre-migration Verification

### P0. Confirm maps leaf contract (S)

- [~] P0.1 Confirm the OR maps leaf `id`, its frontend registration call, and the pinned — deferred to downstream cycle / fleet-wide adoption (handoff)
  `@conduction/nextcloud-vue` version that ships it. Record in design.md DEFERRED_QUESTIONS.
- [~] P0.2 Confirm whether the maps leaf supports a multi-object overview surface; if not, open a — deferred to downstream cycle / fleet-wide adoption (handoff)
  GH issue against OR for `case-map-overview` and link it here.

## [procest] Wire the leaf

### P1. Whitelist + render (M)

- [~] P1.1 Add the maps leaf to the `case` schema `configuration.linkedTypes` whitelist in the — deferred to downstream cycle / fleet-wide adoption (handoff)
  register definition (`lib/Settings/procest_register.json`).
- [~] P1.2 Render the maps leaf tab/widget on the case detail page; confirm the marker reads the — deferred to downstream cycle / fleet-wide adoption (handoff)
  case `location` geo property.
- [~] P1.3 Verify empty-location graceful degradation. — deferred to downstream cycle / fleet-wide adoption (handoff)

## [procest] Remove in-app stack

### P2. Delete superseded UI + services (M)

- [~] P2.1 Remove `src/components/map/*.vue` (MapComponent, CaseMap, LocationPicker, AddressSearch, — deferred to downstream cycle / fleet-wide adoption (handoff)
  MapLayerSwitcher, MapLegend, SpatialFilter, CasePopup) and their imports.
- [~] P2.2 Remove `lib/Service/WmsWfsService.php`, `lib/Service/WfsExportService.php`, — deferred to downstream cycle / fleet-wide adoption (handoff)
  `lib/Service/LocationService.php` and any DI registration.
- [~] P2.3 Confirm the `case` schema `location` geo property is unchanged. — deferred to downstream cycle / fleet-wide adoption (handoff)

## [procest] Spec housekeeping

### P3. Sunset superseded specs (S)

- [~] P3.1 Mark `map-component`, `wms-wfs-layers`, `case-map-overview` for sunset; keep — deferred to downstream cycle / fleet-wide adoption (handoff)
  `case-location` as the geo data contract with a note that rendering is leaf-delegated.

## [procest] Quality gates

### P4. Verify (S)

- [~] P4.1 `openspec validate migrate-maps-to-maps-leaf --strict` exits 0. — deferred to downstream cycle / fleet-wide adoption (handoff)
- [~] P4.2 `composer check:strict` and `npm run lint` pass after removals. — deferred to downstream cycle / fleet-wide adoption (handoff)
