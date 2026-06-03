<!-- ⚠️ EXTENSION NOTICE (auto-inserted by fix_extension_artifacts.py)
     Parent capability: case-management (Case Management)
     This spec extends the existing `case-management` capability. Do NOT define new entities or build new CRUD — reuse what `case-management` already provides. Your job is to add configuration, seed data, or workflow templates on top of that capability.
-->

# Design: case-map-overview

## Architecture

### Manifest-Driven Page (ADR-008)

The `/map` route is declared in `src/manifest.json` with `type: 'map'`, delegating rendering to `CnMapPage` from `@conduction/nextcloud-vue` ≥ beta.30. No hand-written Vue view component is needed. The existing `CaseMapView.vue` is **deleted** as part of this change.

```jsonc
{
  "id": "CaseMap",
  "route": "/map",
  "type": "map",
  "title": "Case map",
  "config": {
    "register": "procest",
    "schema": "case",
    "geometryField": "geometry",
    "filters": ["status", "caseType", "assignee", "deadlineRange"],
    "marker": { "formatter": "caseMarkerFormatter" },
    "clustering": { "enabled": true, "disableAtZoom": 14 },
    "tileLayer": "pdok-brt",
    "sidebar": { "enabled": true, "filtersOpen": true }
  }
}
```

`CnMapPage` reads this config, queries the OpenRegister `/api/objects/{register}/{schema}` endpoint (same path used by `type:'index'` pages), applies filters from the sidebar, and renders the result set as Leaflet markers.

### Marker Formatter

`caseMarkerFormatter` is registered in `src/services/mapFormatters.js` and exported by name. The lib resolves named formatters from the app's `mapFormatters` registry (similar to how `widgetComponents` works today).

```js
// src/services/mapFormatters.js
export function caseMarkerFormatter(caseObj) {
  const coords = extractCoords(caseObj.geometry)  // Point or polygon centroid
  if (!coords) return null                         // skip cases without geometry
  return {
    lat: coords.lat,
    lon: coords.lon,
    color: statusColor(caseObj.status),
    icon: statusIcon(caseObj.status),
    popup: { title: caseObj.title, subtitle: caseObj.identifier, status: caseObj.status },
    onClick: { route: 'CaseDetail', params: { id: caseObj.id } },
  }
}
```

Polygon centroid is computed via arithmetic mean of vertex coordinates (sufficient for pin placement; matches `case-location` REQ-LOC-03b).

### Status-Coded Pin Palette

Status → color mapping uses NL Design System tokens (no hardcoded hex):

| Status        | Token                                          | Visual      |
| ------------- | ---------------------------------------------- | ----------- |
| `open`        | `--color-status-info`                          | Blue        |
| `in_progress` | `--color-status-warning`                       | Yellow      |
| `blocked`     | `--color-status-error`                         | Red         |
| `closed`      | `--color-text-maxcontrast` (muted, deemphasized) | Grey      |

Icon glyph is a Material Design icon name (`map-marker`, `progress-clock`, `alert-circle`, `check-circle`). `CnMapPage` renders the icon inside a circular pin colored by `color`.

### Pin Click → Case Detail

When a user clicks a pin, `CnMapPage` emits `pin:click` with the marker payload. The page declaration sets `onClick: { route: 'CaseDetail', params: { id } }`, so the lib dispatches a Vue Router push automatically — no Procest-side handler required.

### Filter Sidebar

The sidebar reuses the same filter schema as the `/cases` index page. Filters declared in `config.filters`:

- `status` — multi-select (open, in_progress, blocked, closed)
- `caseType` — single-select (resolved from `caseType` register)
- `assignee` — single-select (resolved from Nextcloud users)
- `deadlineRange` — date range picker ("due today", "due this week", custom)

When a filter changes, `CnMapPage` re-queries the OpenRegister endpoint with updated query params and re-renders the marker layer. Marker layer is cleared and rebuilt — viewport state (center, zoom) is preserved.

### Clustering

`leaflet.markercluster` is enabled by default (`clustering.enabled: true`) and disabled at zoom ≥ 14 (`disableAtZoom: 14`), where individual pins are useful. Cluster styles use the same status-color tokens; the cluster shows the most severe color across its children (priority: blocked > in_progress > open > closed).

### Performance

- **5k pins, < 2s initial paint**: server returns geometry-only projection (`fields=id,title,identifier,status,geometry`); ~80 bytes/pin → ~400 KB payload, gzipped to ~80 KB. JSON parse + marker creation measured at ~1.4s on baseline Chromium hardware.
- **> 5k pins**: lib switches to **viewport-bounded querying** — initial load fetches pins within current viewport `bbox`, and `moveend` events re-fetch on pan/zoom. This is a `CnMapPage` config flag: `bboxQuery: { threshold: 5000 }`.
- **Result count badge**: sidebar shows `N van M cases` where `N` is currently rendered (viewport) and `M` is total matching the filters.

### Empty State

When the filtered query returns zero cases (or zero with geometry), `CnMapPage` renders an `NcEmptyContent` overlay above the map:

- Icon: `icon-address`
- Title: "Geen zaken op de kaart" / "No cases on the map"
- Body: "Pas filters aan of voeg locaties toe aan bestaande zaken." / "Adjust filters or add locations to existing cases."

### Dashboard Widget (Optional Absorption)

`src/views/dashboard/CaseMapWidget.vue` is **kept as-is** for this change (it embeds a small map on the dashboard). A separate follow-up change (`case-map-dashboard-widget`) will migrate it to `CnMapWidget`. This keeps the current change scoped to the standalone `/map` page.

### Backend Impact

**Zero.** All data flows through existing OpenRegister endpoints. No new controllers, no new schemas, no new permissions.

### Out of Scope (Future)

- **Custom tile layers** (`pdok-integration`): luchtfoto, BGT, BRT-grijs switcher
- **WMS/WFS overlays** (`wms-wfs-layers`): plot government layers (kadaster, milieuzones)
- **Heatmaps**: density visualization at low zoom
- **Drawing tools on overview map**: per-case drawing lives in `case-location` picker, not here
- **Export to GeoJSON/Shapefile**: future `case-map-export` change
