# Frontend Build Hygiene — Dependency Set Matches Actual Usage

**Spec refs**: none pre-existing (new capability); observed alongside the 2026-07-06 manifest
fleet audit's "dead validator/bundle" finding pattern.

## ADDED Requirements

### Requirement: Declared runtime dependencies MUST be imported somewhere in `src/`

Every package listed in `package.json` `dependencies` MUST have at least one static import (or a
documented dynamic-import path) somewhere under `src/`. A dependency with zero references is dead
weight in the install tree, the license report, and the security-audit surface, and MUST be
removed rather than carried forward "in case it's needed later."

**Feature tier**: internal / build-quality (not user-facing)

#### Scenario: `leaflet.markercluster` has no consumer

- **GIVEN** `package.json` lists `leaflet.markercluster` as a dependency
- **WHEN** `src/` is searched for any import of `leaflet.markercluster` or use of
  `MarkerCluster`/`markerClusterGroup`
- **THEN** zero matches are found
- **AND** the package MUST be removed from `package.json` and `package-lock.json`

#### Scenario: Remaining Leaflet dependencies stay because they are consumed

- **GIVEN** `package.json` lists `leaflet` and `leaflet-draw`
- **WHEN** `src/components/map/LocationPicker.vue` is inspected
- **THEN** both packages are statically imported and actively used for the point/polygon location
  picker
- **AND** both packages remain in `package.json`
