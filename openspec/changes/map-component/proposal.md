# Proposal: map-component

## Summary

Add a reusable Leaflet-based map component to Procest that renders GeoJSON geometries, supports multiple PDOK tile layers, and can be embedded in case detail views, dashboards, and admin settings. The component handles coordinate system conversion (RD to WGS84), marker clustering for large datasets, keyboard navigation, and responsive sizing.

## Motivation

Location-based case management (VTH, omgevingsvergunning, toezicht) requires visualizing case geometries on a map. Case workers need to see where a case is located, inspect surrounding context, and navigate between nearby cases. Without a map component, location data stored in `case.geometry` (GeoJSON) is invisible to users. The `mapLayer` entity already exists in the data model to support configurable GIS layers — this change implements the Vue component and admin configuration UI that activates that capability.

## Affected Projects

- [ ] Project: `procest` — Add CaseMap Vue component, MapLayerSettings admin section, coordinate conversion utilities, and integration into case detail view

## Scope

### In Scope (V1)

- **REQ-MAP-01**: Reusable `CaseMap` Vue component based on Leaflet with PDOK base layers, layer switcher, zoom controls, attribution, and responsive sizing
- **REQ-MAP-02**: GeoJSON geometry rendering (Point, LineString, Polygon, MultiPolygon) with configurable styling, auto-zoom to bounds, and marker popups
- **REQ-MAP-03**: Marker clustering using Leaflet.markercluster for large datasets — cluster color coding, zoom-to-split, spiderfy at max zoom
- **REQ-MAP-04**: Keyboard navigation (arrow keys, +/- zoom) and WCAG AA screen reader support (`role="application"`, `aria-label`, per-marker accessible labels)

### Out of Scope

- WFS/GeoJSON overlay layers from external services (map layer types `wfs` and `geojson` — admin configuration scaffolding is included but live service rendering is deferred to V2)
- Backend GIS proxy for CORS-restricted services (the `proxyEnabled` field is modelled in `mapLayer` but the PHP proxy controller is deferred)
- Fullscreen map view as a standalone page — map is always embedded
- Drawing/editing geometries on the map

## Approach

1. **Frontend**: Create `src/components/CaseMap.vue` as the primary reusable component. Wrap Leaflet lifecycle (init, destroy, resize) in Vue's `mounted`/`beforeDestroy` hooks. Use `vue2-leaflet` for declarative layer management. Add `proj4` + `proj4leaflet` for RD/WGS84 coordinate conversion.
2. **Clustering**: Integrate `leaflet.markercluster` for multi-point cases. Cluster icon factory applies colour coding based on count thresholds.
3. **Admin settings**: Add a `MapLayerSettings.vue` section to `AdminRoot.vue` that allows admins to create, edit, reorder, and delete `mapLayer` objects via the OpenRegister object store. Default PDOK layers are seeded on first load if none exist.
4. **Case detail integration**: Render `CaseMap` in `CaseDetail.vue` when `case.geometry` is non-null. Pass geometry as a prop; the component handles rendering without needing to know about the case entity.
