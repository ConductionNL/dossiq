# case-map-overview Context Brief

## Purpose

Situational-awareness map page at `/map` that plots all cases (or a filtered subset) as pins on an interactive Leaflet map. Adopts `CnMapPage` from `@conduction/nextcloud-vue` (≥ beta.30) via manifest-driven `type: 'map'`, replacing the hand-written `CaseMapView.vue`.

**Standards**: GeoJSON (RFC 7946), NL Design System tokens, EPSG:3857, ADR-008 (manifest-driven views)
**Feature tier**: V1
**Depends on**: `case-location` (provides `case.geometry` field and seed data)
**Foundation for**: `pdok-integration` (luchtfoto/BGT layers), `wms-wfs-layers` (custom government layers)

## Requirements Summary

- **REQ-CMO-1**: Manifest-driven map page at `/map` via `CnMapPage`; delete `CaseMapView.vue`
- **REQ-CMO-2**: `caseMarkerFormatter` projecting case → `{lat, lon, color, icon, popup, onClick}` (Point + Polygon centroid)
- **REQ-CMO-3**: Status-coded palette (open/in_progress/blocked/closed) using NL Design System CSS-variable tokens — no hardcoded hex
- **REQ-CMO-4**: Pin click navigates to `CaseDetail` route, preserving back-stack and viewport
- **REQ-CMO-5**: Filter sidebar wired to existing case index filters (status, caseType, assignee, deadlineRange)
- **REQ-CMO-6**: Marker clustering enabled at zoom < 14 via leaflet.markercluster; cluster color = most severe child
- **REQ-CMO-7**: Empty-state overlay when zero pins match (or zero have geometry)
- **REQ-CMO-8**: Performance — 5k pins under 2s initial paint; viewport-bounded query above 5k

## Impact

- **Frontend**: `src/manifest.json` (CaseMap entry), new `src/services/mapFormatters.js` + `src/services/caseStatusPalette.js`, delete `src/views/CaseMapView.vue`
- **Backend**: None
- **Schema**: None
- **Deps**: Bump `@conduction/nextcloud-vue` peer to `^beta.30`
