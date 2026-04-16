---
status: partial
---
# case-map-overview Specification

## Purpose

Provide an overview map showing all cases (or filtered subsets) plotted on an interactive map with marker clustering, status-based coloring, and interactive filtering. Enables case handlers and managers to get a geographic overview of their workload and identify spatial patterns (e.g., clusters of meldingen in a neighbourhood, VTH cases near a construction site).

**Standards:** GeoJSON (RFC 7946), PDOK tile services, CBS Wijken en Buurten WFS
**Feature tier:** V1

## Context

Procest stores GeoJSON geometry on the `case` entity (field `case.geometry`, ADR-000 Group 2). The `mapLayer` entity (ADR-000 Group 7) configures which tile, WMS, and WFS layers appear in the map UI. A Leaflet.js stack (leaflet, leaflet-draw, leaflet.markercluster) is already bundled in the app.

In the Dutch municipal context, spatial overviews are essential for VTH (Vergunning, Toezicht, Handhaving) case management: inspectors need to see which addresses have open enforcement cases, managers need to spot clusters of meldingen in specific wijken, and coordinators need to assign cases by geographic district.

---

## REQ-OVERVIEW-01: Cases Map View

**Feature tier:** V1

The system MUST provide a map overview of all cases with geometry, accessible from the main navigation as "Kaart".

### Scenario OVERVIEW-01a: Display all cases on map

- GIVEN 150 active cases with geometry data stored in `case.geometry`
- WHEN the user navigates to "Kaart" in the Procest navigation
- THEN a full-width map MUST be displayed showing all case locations
- AND cases MUST be rendered as markers (Point geometry) or polygons (Polygon/LineString geometry) depending on GeoJSON type
- AND marker clustering (leaflet.markercluster) MUST be active at low zoom levels
- AND the map MUST auto-fit bounds to show all case markers on initial load

### Scenario OVERVIEW-01b: Case popup on marker click

- GIVEN cases are displayed on the overview map
- WHEN the user clicks a case marker
- THEN a popup MUST show: case title, identifier, status (colored badge), assignee, and case type name
- AND the popup MUST include a "Bekijk zaak" link that navigates to the case detail view (`/cases/:id`)

### Scenario OVERVIEW-01c: Status-based marker coloring

- GIVEN cases with different statuses and deadlines
- WHEN displayed on the overview map
- THEN markers MUST be color-coded by status category:
  - **Green** (`#4CAF50`): completed/closed cases (`endDate` set)
  - **Blue** (`#2196F3`): active/in-progress cases
  - **Orange** (`#FF9800`): cases nearing deadline (deadline within 5 days and not yet past)
  - **Red** (`#F44336`): overdue cases (deadline in the past, `endDate` not set)
- AND a color legend MUST be visible in the bottom-right map corner

---

## REQ-OVERVIEW-02: Map Filters

**Feature tier:** V1

The overview map MUST support filtering cases by standard attributes.

### Scenario OVERVIEW-02a: Filter by case type

- GIVEN the overview map with cases of types "Omgevingsvergunning", "Klacht", "Subsidie"
- WHEN the user selects "Omgevingsvergunning" in the case type filter (multi-select)
- THEN only omgevingsvergunning cases MUST be displayed on the map
- AND the visible marker count MUST update
- AND cluster counts MUST recalculate based on the filtered set

### Scenario OVERVIEW-02b: Filter by status

- GIVEN the overview map showing all cases
- WHEN the user toggles off "Afgerond" in the status filter
- THEN completed cases (green markers) MUST be hidden from the map
- AND remaining markers MUST re-cluster

### Scenario OVERVIEW-02c: Filter by assignee ("Mijn zaken")

- GIVEN the overview map
- WHEN the user activates the "Mijn zaken" toggle
- THEN only cases where `case.assignee` equals the current Nextcloud user ID MUST be displayed

### Scenario OVERVIEW-02d: Combined filters with badge

- GIVEN multiple filters active: case type "Klacht" AND status "Open"
- WHEN both filters are applied simultaneously
- THEN only open klacht cases MUST be displayed on the map
- AND a filter summary badge MUST show "2 filters actief"
- AND the badge count MUST reflect the number of independently active filter dimensions

---

## REQ-OVERVIEW-03: Spatial Selection

**Feature tier:** V1

The overview map MUST support selecting cases by geographic area using drawing tools or administrative boundaries.

### Scenario OVERVIEW-03a: Rectangle selection

- GIVEN the overview map with cases
- WHEN the user activates the "Gebied selecteren" tool and draws a rectangle on the map by clicking and dragging
- THEN all cases whose geometry falls within the rectangle MUST be highlighted
- AND a `SpatialSelectionSidebar` MUST appear listing the selected cases with their title, identifier, and status
- AND the sidebar MUST show the total count of selected cases
- AND the sidebar MUST include bulk action options (if user has the required permission)

### Scenario OVERVIEW-03b: Polygon selection with area display

- GIVEN the "Gebied selecteren" tool is active in polygon mode
- WHEN the user draws a freeform polygon by clicking points on the map
- THEN cases within the polygon MUST be selected using point-in-polygon calculation
- AND the polygon area in m² (for areas < 10 000 m²) or ha (for areas ≥ 10 000 m²) MUST be displayed below the drawing toolbar
- AND the area MUST be calculated using geodesic area computation (accounts for Earth's curvature)

### Scenario OVERVIEW-03c: Wijk/buurt selection

- GIVEN a `mapLayer` object with `layerType: "wfs"` and `layers: "wijken"` is configured (CBS wijken/buurten WFS)
- WHEN the user activates the "Selecteer wijk" tool and clicks on a wijk polygon on the map
- THEN all cases whose geometry falls within that wijk polygon MUST be selected
- AND the wijk name and CBS wijk code MUST be displayed in the `SpatialSelectionSidebar` header
- AND the selection MUST use the same point-in-polygon logic as polygon selection

---

## REQ-OVERVIEW-04: Dashboard Map Widget

**Feature tier:** V1

The Procest dashboard MUST support a map widget showing the current user's assigned case locations.

### Scenario OVERVIEW-04a: Map widget on dashboard

- GIVEN the dashboard is loaded by a user with assigned cases that have `geometry` data
- WHEN the dashboard renders
- THEN a compact `CaseMapWidget` MUST be rendered showing only cases where `case.assignee` equals the current user
- AND the widget map MUST use marker clustering
- AND clicking a marker popup "Bekijk zaak" link MUST navigate to the case detail view

### Scenario OVERVIEW-04b: Widget minimum height

- GIVEN the map widget is rendered on the dashboard
- THEN the widget container MUST be at least **400 px** in height
- AND the widget MUST respect the Nextcloud dashboard grid layout without overflowing adjacent widgets

---

## Dependencies

- **OpenRegister** — `case.geometry` (JSON field) and `mapLayer` schema for layer configuration
- **Leaflet.js** — Map rendering (`leaflet ^1.9.4`, already in `package.json`)
- **leaflet-draw** — Drawing tools for rectangle/polygon selection (`leaflet-draw ^1.0.4`)
- **leaflet.markercluster** — Cluster grouping (`leaflet.markercluster ^1.5.3`)
- **PDOK tile services** — Base map tiles (public, no auth required)
- **CBS Wijken en Buurten WFS** — Administrative boundary layer via `service.pdok.nl/cbs/wijkenbuurten`
- **GIS proxy** (`/api/gis/proxy`) — Backend route for CORS-restricted GIS requests

---

## Current Implementation Status

### Implemented

| Component | Status |
|-----------|--------|
| `CaseMap.vue` | Complete — Leaflet, PDOK tiles, clustering, status colors, auto-fit, legend |
| `CasePopup.vue` | Complete — title, identifier, status badge, case type, assignee, "Bekijk zaak" link |
| `MapLegend.vue` | Complete — green/blue/orange/red legend |
| `MapLayerSwitcher.vue` | Complete — base layer + overlay switcher |
| `CaseMapView.vue` | Complete — filter panel (case type, status toggles, my-cases, filter count badge) |
| `CaseMapWidget.vue` | Complete — dashboard widget, 400 px min-height, current-user filter |
| `gis.js` store | Complete — mapLayer CRUD, spatial filter state |
| Navigation item "Kaart" | Complete — `MainMenu.vue` has Map nav item → CaseMap route |
| Router route `/map` | Complete |
| GIS proxy routes | Complete — `POST /api/gis/proxy`, `GET /api/gis/capabilities` |

### Not Yet Implemented

| Component / Feature | REQ | Gap |
|---------------------|-----|-----|
| `SpatialSelectionSidebar.vue` | OVERVIEW-03a/b/c | New component needed |
| Polygon area display (m²/ha) | OVERVIEW-03b | Add to `SpatialFilter.vue` |
| Wijk/buurt selection via CBS WFS | OVERVIEW-03c | Add to `SpatialFilter.vue` |
| Seed data: 5 `case` objects with geometry | all | Add to `procest_register.json` |
| Seed data: 5 `mapLayer` objects | all | Add to `procest_register.json` |

---

## Standards & References

- **GeoJSON (RFC 7946)** — `case.geometry` stores GeoJSON geometry objects
- **PDOK WMTS v2.0** — BRT Achtergrondkaart tile service (Kadaster)
- **CBS Wijken en Buurten WFS** — Administrative boundaries for wijk/buurt selection
- **WCAG AA** — Map MUST have keyboard-accessible alternative; markers MUST have `aria-label`; color is not the sole method of conveying status (shape + legend also used)
- **NL Design System tokens** — Filter panel and sidebar use `var(--color-primary-element)` etc.; no hardcoded colors outside Leaflet marker icons
