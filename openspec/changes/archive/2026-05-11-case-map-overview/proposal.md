<!-- ⚠️ EXTENSION NOTICE (auto-inserted by fix_extension_artifacts.py)
     Parent capability: case-management (Case Management)
     This spec extends the existing `case-management` capability. Do NOT define new entities or build new CRUD — reuse what `case-management` already provides. Your job is to add configuration, seed data, or workflow templates on top of that capability.
-->

# Case Map Overview Specification

## Why

Procest already has a `/map` route backed by a hand-written `CaseMapView.vue` component. With the sister change `case-location` shipping per-case `geometry` editing, the natural next step is a **situational-awareness map** that plots all cases (or a filtered subset) as pins — answering questions like "where are my open complaints today?" or "which inspections are due this week?".

Nextcloud-vue **beta.30** introduces `CnMapPage` / `CnMapWidget` — a manifest-driven map page type that handles tile layers, pin formatting, clustering, and filter wiring out of the box. Adopting it lets Procest delete the bespoke `CaseMapView.vue` and instead declare `type: 'map'` in `src/manifest.json`, aligning with ADR-008 (manifest-driven views) and the broader procest store/abstraction migration.

Tender analysis shows ~300 unique tenders requiring a "map overview of cases" for VTH, inspecties, and meldingen-openbare-ruimte teams. This is V1 scope and is a prerequisite for `pdok-integration` (luchtfoto + BGT layers) and `wms-wfs-layers` (custom government layers), both planned next.

## What Changes

- **REQ-CMO-01**: Convert the `CaseMap` manifest page from `type: 'custom'` to `type: 'map'` consuming `CnMapPage` from `@conduction/nextcloud-vue` (≥ beta.30); delete the bespoke `CaseMapView.vue`
- **REQ-CMO-02**: Define a case-marker formatter that maps each case object to `{lat, lon, icon, color, popup}` based on `case.geometry` (Point) or polygon centroid (Polygon)
- **REQ-CMO-03**: Status-coded pin palette (open/in-progress/blocked/closed) using NL Design System tokens
- **REQ-CMO-04**: Pin click navigates to `CaseDetail` route (`/cases/:id`)
- **REQ-CMO-05**: Filter sidebar wired to existing case index filters (status, caseType, assignee, deadline window) — selecting a filter re-queries the pin set
- **REQ-CMO-06**: Pin clustering enabled at zoom < 14 for high-density areas (Leaflet.markercluster)
- **REQ-CMO-07**: Empty state when zero cases match (or zero have geometry)
- **REQ-CMO-08**: Performance budget — initial paint < 2s for 5,000 pins; viewport-bounded queries for sets > 5,000

## Capabilities

### New Capabilities
- `case-map-page`: Manifest-driven map page at `/map` consuming `CnMapPage`
- `case-pin-formatter`: Case → marker projection with status-coded icon
- `case-map-filter-sidebar`: Filter sidebar wired to pin query
- `case-map-clustering`: Marker clustering at low zoom levels

### Modified Capabilities
- `case-management`: extended by `case-map-overview` — adds a map navigation page on top of existing case data; no new entities

## Impact

- **Frontend**: Update `src/manifest.json` `CaseMap` page entry; delete `src/views/CaseMapView.vue` (and `src/views/dashboard/CaseMapWidget.vue` if absorbed by lib widget); no changes to `case` schema
- **Backend**: No new PHP controllers — pin data is queried via the existing OpenRegister `searchObjects` endpoint with the same filters used by `/cases`
- **Register config**: No new schemas — `case.geometry` already exists (provided by `case-location` change)
- **Dependencies**: Bump `@conduction/nextcloud-vue` peer to `^beta.30`; add `leaflet.markercluster` (already transitively pulled by `CnMapPage`)
- **Future-facing**: This page is the host surface for `pdok-integration` (luchtfoto, BGT, BRT layer switcher) and `wms-wfs-layers` (custom layers from `mapLayer` register)

## Standards & References

- **GeoJSON** (RFC 7946) — geometry storage format read from `case.geometry`
- **NL Design System** color tokens — status palette (no hardcoded hex)
- **EPSG:3857** (Web Mercator) — default Leaflet projection
- **ADR-008** (manifest-driven views) — page declared in `src/manifest.json`
- **Feature tier**: V1
