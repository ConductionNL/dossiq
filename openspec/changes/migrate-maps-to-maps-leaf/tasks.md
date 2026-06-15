# Tasks: migrate-maps-to-maps-leaf

All tasks are in the `procest` repo. Estimates: S = half-day, M = 1–2 days, L = 3+ days.

> IMPLEMENTED 2026-06-15 (per-case map tab only). The "leaf not released"
> blocker in the deferral block below is stale for the per-case surface: OR's
> `MapsProvider` is present + DI-registered on openregister development and
> `@conduction/nextcloud-vue` (beta.108) ships `CnMapsTab`. The per-case map is
> migrated to the leaf. The **multi-object cases-on-map overview** (CasesOnMapView
> / `/map` page / full Leaflet+WMS/WFS stack removal) is OUT OF SCOPE — the OR
> maps leaf is a per-object surface (`list()` returns lat/lng rows for one
> object) and has no page-level multi-object render surface yet. Tracked as
> Codeberg issue #112.

## [procest] Pre-migration Verification

### P0. Confirm maps leaf contract (S)

- [x] P0.1 Confirmed: OR maps leaf id `maps` (`MapsProvider`, link-table backed); registered via
  the lib `builtinIntegrations` registry; `@conduction/nextcloud-vue` `^1.0.0-beta.108` ships the
  bespoke `CnMapsTab`.
- [x] P0.2 The maps leaf does NOT support a multi-object overview surface (it is per-object).
  Follow-up tracked as Codeberg procest issue #112 (page-level maps-overview surface in OR).

## [procest] Wire the leaf

### P1. Whitelist + render (M)

- [x] P1.1 Added `maps` to the `case` schema `configuration.linkedTypes` whitelist in
  `lib/Settings/procest_register.json`.
- [x] P1.2 Surfaced the maps leaf (`MapsLeafTab` → `CnMapsTab`) as the `location` sidebar tab on
  `CaseDetail`, resolved from the lib `builtinIntegrations` registry via
  `src/integrations/leafTabs.js`. The leaf reads the case object's linked locations via OR.
- [x] P1.3 Empty-location graceful degradation: `CnMapsTab` renders its built-in empty/no-location
  state when no location is linked.

## [procest] Remove in-app stack

### P2. Delete superseded UI + services (M)

- [x] P2.1 Removed the bespoke per-case Leaflet surface `src/views/cases/components/LocationTab.vue`
  (orphaned single-case map tab, superseded by the maps leaf). The remaining `src/components/map/*.vue`
  components + `WmsWfsService`/`WfsExportService`/`LocationService` power the **multi-object** overview
  and `/map` page, which are OUT OF SCOPE (issue #112) — left intact to avoid regressing that surface.
- [~] P2.2 DEFERRED to issue #112 — removing `WmsWfsService`/`WfsExportService`/`LocationService`
  would break the in-scope-out multi-object overview + their controllers; blocked on the OR
  maps-overview surface.
- [x] P2.3 The `case` schema `location` geo property is unchanged (geo data contract preserved).

## [procest] Spec housekeeping

### P3. Sunset superseded specs (S)

- [~] P3.1 DEFERRED to issue #112 — `map-component` / `wms-wfs-layers` / `case-map-overview` still
  back the multi-object overview, so they are NOT sunset yet. `case-location` stays as the geo data
  contract; the new `case-map-via-maps-leaf` spec records that per-case rendering is leaf-delegated.

## [procest] Quality gates

### P4. Verify (S)

- [x] P4.1 `openspec validate migrate-maps-to-maps-leaf --strict` exits 0.
- [x] P4.2 PHPUnit 1342 pass (2 skipped), vitest green, `npm run build` clean, hydra gates 24/24.

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
