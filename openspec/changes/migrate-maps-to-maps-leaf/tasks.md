# Tasks: migrate-maps-to-maps-leaf

All tasks are in the `procest` repo. Estimates: S = half-day, M = 1–2 days, L = 3+ days.
Implementation runs through Hydra; this change is specs-only.

## [procest] Pre-migration Verification

### P0. Confirm maps leaf contract (S)

- [~] P0.1 Confirm the OR maps leaf `id`, its frontend registration call, and the pinned
  `@conduction/nextcloud-vue` version that ships it. Record in design.md DEFERRED_QUESTIONS.
- [~] P0.2 Confirm whether the maps leaf supports a multi-object overview surface; if not, open a
  GH issue against OR for `case-map-overview` and link it here.

## [procest] Wire the leaf

### P1. Whitelist + render (M)

- [~] P1.1 Add the maps leaf to the `case` schema `configuration.linkedTypes` whitelist in the
  register definition (`lib/Settings/procest_register.json`).
- [~] P1.2 Render the maps leaf tab/widget on the case detail page; confirm the marker reads the
  case `location` geo property.
- [~] P1.3 Verify empty-location graceful degradation.

## [procest] Remove in-app stack

### P2. Delete superseded UI + services (M)

- [~] P2.1 Remove `src/components/map/*.vue` (MapComponent, CaseMap, LocationPicker, AddressSearch,
  MapLayerSwitcher, MapLegend, SpatialFilter, CasePopup) and their imports.
- [~] P2.2 Remove `lib/Service/WmsWfsService.php`, `lib/Service/WfsExportService.php`,
  `lib/Service/LocationService.php` and any DI registration.
- [~] P2.3 Confirm the `case` schema `location` geo property is unchanged.

## [procest] Spec housekeeping

### P3. Sunset superseded specs (S)

- [~] P3.1 Mark `map-component`, `wms-wfs-layers`, `case-map-overview` for sunset; keep
  `case-location` as the geo data contract with a note that rendering is leaf-delegated.

## [procest] Quality gates

### P4. Verify (S)

- [~] P4.1 `openspec validate migrate-maps-to-maps-leaf --strict` exits 0.
- [~] P4.2 `composer check:strict` and `npm run lint` pass after removals.

## Deferral block (final-77 sweep, 2026-06-11)

All open tasks above were converted from `[ ]` to `[~]` in one mechanical
pass. The deferral reason is uniform: this is a **fleet-level migration**
whose target consumes either OpenRegister leaf or an openconnector centralised
service that lives outside the procest repo. Per ADR-019 (integration leaves)
and ADR-022 (apps consume OR abstractions):

- The migration requires the target leaf to be released, versioned, and
  tested in the central library (e.g. `@nextcloud-vue` analytics leaf,
  OR `shares` / `calendar` / `maps` / `forms` / `tenant` /
  `approval-workflow` / `audit` / `lifecycle` / `rbac` integration
  leaves, or the openconnector PDOK connector).
- Several entries above explicitly note "REVERTED 2026-06-01: archived
  prematurely" — that's a separate problem-shape (proposal lifecycle drift)
  and does NOT mean the migration code itself has landed; the bespoke
  in-app implementation is still the source of truth in procest.
- Procest's existing service surface continues to ship (no regressions);
  the migration is a follow-up that lands across multiple repos in one
  coordinated PR train per leaf.

Each `[~]` task therefore inherits this single concrete blocker: **target
leaf / centralised connector not yet released for procest to consume**. The
follow-up will tick them on a per-leaf basis as the central libraries ship.
