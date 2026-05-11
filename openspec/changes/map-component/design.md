# Design: map-component

## Architecture

### One Wrapper, Three Surfaces

`MapComponent.vue` lives at `src/components/map/MapComponent.vue` and is a thin Procest-side wrapper around `CnMapWidget` from `@conduction/nextcloud-vue`. It exists for three reasons that the lib component alone cannot solve:

1. **Procest-specific defaults** — default tile layer (`pdok-brt`), default centre (Netherlands lat 52.1326, lon 5.2913, zoom 7), default marker formatter (`caseMarkerFormatter` from `src/services/mapFormatters.js`).
2. **A stable Procest API** — sister specs (`case-location` picker, `case-map-overview` widget, public case pages) all reference `<MapComponent>` rather than the lib component, so a lib upgrade is a single-file change.
3. **Mode switching** — `interactive` prop toggles read-only vs. read-write panning/zooming, used by the public case page.

The component is **stateless** with respect to data: it owns no store. Parents pass `locations[]` and listen for events. Internal state (current viewport, hover marker) is local component state only.

## Component API

### Props

| Prop | Type | Default | Description |
|------|------|---------|-------------|
| `locations` | `Array<{ id, geometry, ...meta }>` | `[]` | Objects with GeoJSON `geometry` (Point or Polygon); meta passed back unchanged in events |
| `center` | `{ lat: number, lon: number }` | `{ lat: 52.1326, lon: 5.2913 }` | Initial map centre |
| `zoom` | `number` | `7` | Initial zoom level |
| `interactive` | `boolean` | `true` | When `false`, disables pan/zoom and hides zoom controls (public read-only mode) |
| `markerFormatter` | `string` | `"caseMarkerFormatter"` | Name of a formatter registered in `app.$mapFormatters` |
| `tileLayer` | `string` | `"pdok-brt"` | Tile layer key resolved by `CnMapWidget` |
| `clustering` | `boolean` | `true` | Enable `leaflet.markercluster` at zoom < 14 |
| `height` | `string` | `"400px"` | CSS height (parent typically overrides) |

### Events

| Event | Payload | When |
|-------|---------|------|
| `marker-click` | `{ marker, location }` | User clicks a marker — `marker` is the formatter output, `location` is the original input object |
| `viewport-change` | `{ center: {lat, lon}, zoom, bbox: [w,s,e,n] }` | Debounced 200 ms after `moveend` / `zoomend` |
| `ready` | `{ map }` | Leaflet map instance is available (rare escape hatch for advanced parents) |

### Marker Icon Strategy

Markers are rendered by the formatter named in `markerFormatter`. The default `caseMarkerFormatter` (already defined by `case-map-overview` T02) returns `{ lat, lon, color, icon, popup, onClick }`. `MapComponent` itself does **not** define formatters — it merely resolves the name from `app.$mapFormatters`. This keeps the wrapper application-agnostic and avoids duplicating palette logic.

Polygon geometries are reduced to centroid markers by the formatter (same arithmetic-mean centroid as `case-map-overview` REQ-CMO-2). Polygons themselves are **not** drawn in MVP — only their centroid pin. Drawing polygon outlines is a future enhancement tracked under `case-location-picker`.

### NL Design System

All colors come from CSS variable tokens via the formatter (no hardcoded hex in `MapComponent.vue`). Container chrome (border, focus ring, attribution text) uses `--color-border`, `--color-primary-element`, `--color-text-maxcontrast`. Empty state when `locations.length === 0` uses `NcEmptyContent` with `icon-address`.

### Accessibility

- Map container: `role="application"`, `aria-label` from i18n key `map.aria-label` (NL: "Kaart met zaaklocaties"; EN: "Map with case locations").
- Keyboard: arrow keys pan when focused, `+`/`-` zoom, `Enter` activates the focused marker.
- Each marker exposes an accessible name via the formatter's `popup.title`.
- When `interactive: false`, the application role is replaced with `role="img"` and the `aria-label` reads `map.aria-label-readonly`.

### Three Integration Points

1. **Case detail map tab** (`src/views/cases/components/CaseMapTab.vue`) — passes the single case's `[caseObj]`, listens to `marker-click` for nothing (single pin) and `viewport-change` to remember last viewport per case.
2. **Dashboard `CaseMapWidget`** (`src/views/dashboard/CaseMapWidget.vue`) — passes the dashboard's filtered case list, listens to `marker-click` to route to case detail.
3. **Public case page** (`src/views/public/PublicCaseView.vue`) — passes `[caseObj]` with `interactive: false`, no event handlers.

### Out of Scope

- **Standalone `/map` page** — owned by `case-map-overview` via `CnMapPage`, which is a different lib component aimed at full-page layout with sidebar. `MapComponent` is the embeddable widget variant.
- **WMS/WFS layers** — future `wms-wfs-layers` change adds layer-switcher config; `MapComponent` will gain a `layers[]` prop then.
- **Drawing / editing tools** — future `case-location-picker` change.
- **Heatmaps, 3D, GPS capture** — not on the procest roadmap.
