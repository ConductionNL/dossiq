# Proposal: case-map-overview

## Summary

Add a geographic case overview to Procest showing all cases plotted on a Leaflet map with PDOK base tiles, marker clustering, status-based coloring, an interactive filter panel, spatial selection tools, and a dashboard map widget. Enables case handlers and managers to identify spatial patterns across their workload.

## Motivation

Cases with location geometry (meldingen, VTH inspections, omgevingsvergunningen) currently have no map overview. Handlers cannot see whether cases cluster near a construction site, which districts have overdue cases, or how many open klachten are in a specific wijk. The `case.geometry` field already stores GeoJSON, but there is no view to visualise it in aggregate.

## Affected Projects

- [x] Project: `procest` — Add CaseMapView, spatial selection components, dashboard map widget, and seed data for mapLayer + geo-enriched cases

## Scope

### In Scope (V1)

- **REQ-OVERVIEW-01**: Full-width map showing all cases with geometry; markers/polygons, clustering, status-based coloring, color legend, and case popup with "Bekijk zaak" link
- **REQ-OVERVIEW-02**: Filter panel — by case type, by status (toggle), "Mijn zaken", combined filter count badge
- **REQ-OVERVIEW-03**: Spatial selection — rectangle, freeform polygon with area (m²/ha), wijk/buurt click via CBS wijken WFS
- **REQ-OVERVIEW-04**: Dashboard map widget showing the current user's assigned cases, 400 px minimum height, marker clustering, popup navigation

### Out of Scope

- Heat map / density layer (V2)
- Route optimisation between case locations (V2)
- GIS export of selected cases as GeoJSON/KML (V2)
- Real-time push updates when new cases with geometry are created (V2)

## Approach

The Leaflet stack (leaflet, leaflet-draw, leaflet.markercluster) is already in `package.json` and a `CaseMap.vue` base component, `SpatialFilter.vue`, `CaseMapWidget.vue`, and `gis.js` store are already built. This change closes the remaining gaps:

1. **Wijk/buurt selection** — extend `SpatialFilter.vue` to load a CBS wijken/buurten WFS layer from a configured `mapLayer` object and handle click-to-select on wijk polygons.
2. **Polygon area display** — add m²/ha area calculation to `SpatialFilter.vue` using Leaflet's `GeometryUtil`.
3. **Selection results sidebar** — create `SpatialSelectionSidebar.vue` listing selected cases with count and bulk-action hooks.
4. **Seed data** — add 5 `case` objects with GeoJSON `geometry` and 5 `mapLayer` objects (PDOK BRT, PDOK Luchtfoto, PDOK BRT Grijs, CBS Wijken WFS, BAG Panden WMS) to `procest_register.json`.
