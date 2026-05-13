# Proposal: Map Component

## Summary

Introduce a single reusable Procest Vue component — `MapComponent` — that wraps `CnMapWidget` from `@conduction/nextcloud-vue` and serves as the shared map surface for every case detail map tab, dashboard map widget, and public case page map. Today three sibling specs (`case-location`, `case-map-overview`, public case pages) each touch maps directly, leading to drift in marker icons, tile layer defaults, accessibility wiring, and event contracts. This change extracts one canonical component with a stable prop and event API so that all three surfaces render the same way.

## Problem

`case-map-overview` consumes `CnMapPage` for the standalone `/map` route, but the case detail "Locaties" tab (REQ-LOC, `case-location`) and the dashboard small-map widget each instantiate Leaflet or `CnMapWidget` independently, with copy-pasted formatter logic, palette tokens, and bbox helpers. Public case pages — once published — will need a third, read-only embedding. Without a shared component, fixes to clustering, RD→WGS84 conversion, marker accessibility, or NL Design System tokens have to land in three places, and the procest-side wrapper around the lib has no single home.

## Scope -- MVP

**In scope:**
- `src/components/map/MapComponent.vue` — a thin procest wrapper around `CnMapWidget`
- Props: `locations[]` (geometry-bearing objects), `center` (lat/lon), `zoom`, `interactive` (boolean), `markerFormatter` (named formatter from `mapFormatters` registry), `tileLayer` (default `pdok-brt`), `clustering` (boolean)
- Events: `marker-click` (payload: marker descriptor + original object), `viewport-change` (payload: `{center, zoom, bbox}`), `ready` (Leaflet map ready)
- Wired into: case detail "Map" tab (read-write picker mode), dashboard `CaseMapWidget`, public case page (read-only mode)
- Registered in `customComponents` so manifest pages can reference it by name
- NL Design System CSS variable tokens only; WCAG AA keyboard + screen reader

**Out of scope:**
- Standalone `/map` route (owned by `case-map-overview`)
- Address validation / PDOK suggest (owned by `case-location`)
- WMS/WFS custom government layers (future `wms-wfs-layers`)
- Drawing / editing tools (future `case-location-picker` change once needed)
- Heatmaps, 3D, mobile-only GPS capture

## Dependencies

- `@conduction/nextcloud-vue` ≥ beta.30 (provides `CnMapWidget`)
- `case-location` REQ-LOC-1..N (provides the `geometry` field this component reads)
- `case-map-overview` (consumes `mapFormatters` registry — same pattern used here)
- Existing `customComponents` registration in `src/main.js`
- NL Design System CSS variable tokens (no hardcoded hex)
