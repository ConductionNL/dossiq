---
status: proposed
---
# map-component Specification

## Purpose

Provide a reusable Leaflet-based map component for Procest that renders GeoJSON geometries, supports multiple tile layers (PDOK), and can be embedded in case detail views, dashboards, and admin settings. The component handles coordinate system conversion (RD to WGS84 via proj4), marker clustering for large datasets, keyboard navigation, and responsive sizing.

**Standards**: GeoJSON (RFC 7946), WGS84 (EPSG:4326), PDOK tile services, WCAG AA

## Context

Case management in the VTH (Vergunning, Toezicht en Handhaving) domain is inherently location-based. The `case` entity already stores geometry as a JSON-encoded GeoJSON object (`case.geometry`). The `mapLayer` entity (Group 7: VTH/Enforcement) stores admin-configurable GIS layer definitions. This spec activates both by providing the Vue component layer that visualizes them.

## Requirements

---

### REQ-MAP-01: Base Map Component

The system MUST provide a reusable Vue map component based on Leaflet that can be embedded in any view.

#### Scenario MAP-01a: Render map with PDOK base layer

- GIVEN the `CaseMap` component is mounted
- WHEN no custom layers are configured
- THEN the map MUST display the PDOK BRT Achtergrondkaart as the default base layer
- AND the map MUST be centered on the Netherlands (lat: 52.1326, lng: 5.2913, zoom: 7)
- AND the map MUST show zoom controls and attribution

#### Scenario MAP-01b: Base layer switcher

- GIVEN the map component is rendered
- WHEN the user clicks the layer switcher control
- THEN the following base layers MUST be available:
  - PDOK BRT Achtergrondkaart (default)
  - PDOK BRT Achtergrondkaart Grijs
  - PDOK Luchtfoto (aerial imagery)
- AND selecting a base layer MUST replace the current base layer (only one visible at a time)

#### Scenario MAP-01c: Responsive sizing

- GIVEN the map is embedded in a container
- WHEN the container is resized (e.g., sidebar toggle)
- THEN the map MUST automatically resize to fill its container
- AND the map MUST call `invalidateSize()` after resize transitions complete

---

### REQ-MAP-02: GeoJSON Geometry Rendering

The map component MUST render GeoJSON geometries (Point, LineString, Polygon, MultiPolygon) with configurable styling.

#### Scenario MAP-02a: Render case location point

- GIVEN a case with geometry `{"type": "Point", "coordinates": [5.1214, 52.0907]}`
- WHEN the map component receives this geometry as a prop
- THEN a marker MUST be displayed at the correct location
- AND clicking the marker MUST show a popup with the case title and identifier

#### Scenario MAP-02b: Render case area polygon

- GIVEN a case with geometry `{"type": "Polygon", "coordinates": [[[5.12, 52.09], [5.13, 52.09], [5.13, 52.10], [5.12, 52.10], [5.12, 52.09]]]}`
- WHEN the map component receives this geometry
- THEN the polygon MUST be rendered with the configured style (default: blue fill, 0.2 opacity)
- AND the map MUST auto-zoom to fit the polygon bounds

#### Scenario MAP-02c: Handle RD coordinates

- GIVEN geometry coordinates in Rijksdriehoekscoordinaten (EPSG:28992) format (e.g., `[155000, 463000]`)
- WHEN the geometry is passed to the map component with prop `crs: "EPSG:28992"`
- THEN the component MUST convert coordinates to WGS84 (EPSG:4326) using proj4 before rendering
- AND the conversion MUST maintain sub-meter accuracy

---

### REQ-MAP-03: Marker Clustering

When displaying multiple case locations, the map MUST use marker clustering to maintain performance and readability.

#### Scenario MAP-03a: Cluster markers at low zoom

- GIVEN 500 cases with point geometries
- WHEN displayed on the map at zoom level 7 (national view)
- THEN nearby markers MUST be grouped into cluster icons showing the count
- AND cluster icons MUST use colour coding: green (<10), yellow (10-50), orange (50-100), red (>100)

#### Scenario MAP-03b: Expand clusters on zoom

- GIVEN a cluster of 25 cases in Amsterdam
- WHEN the user zooms in to street level (zoom 16+)
- THEN individual markers MUST become visible
- AND clicking a marker MUST show the case popup

#### Scenario MAP-03c: Cluster click behaviour

- GIVEN a cluster icon showing "42"
- WHEN the user clicks the cluster
- THEN the map MUST zoom in to the next level where the cluster splits
- AND if at max zoom, the cluster MUST "spiderfy" to show individual markers

---

### REQ-MAP-04: Keyboard and Accessibility

The map component MUST be keyboard-navigable and meet WCAG AA requirements.

#### Scenario MAP-04a: Keyboard navigation

- GIVEN the map component has focus
- WHEN the user presses arrow keys
- THEN the map MUST pan in the corresponding direction
- AND `+`/`-` keys MUST zoom in/out

#### Scenario MAP-04b: Screen reader support

- GIVEN a screen reader is active
- WHEN the map component is rendered
- THEN the map container MUST have `role="application"` and `aria-label="Kaart met zaaklocaties"`
- AND each marker MUST have an accessible label with the case title

---

### REQ-MAP-05: Admin Layer Configuration

Administrators MUST be able to manage `mapLayer` configuration objects via the admin settings interface.

#### Scenario MAP-05a: List configured layers

- GIVEN an admin opens the map settings section in admin settings
- WHEN the page loads
- THEN all configured `mapLayer` objects MUST be listed with title, layerType, isDefault, and order
- AND if no layers exist, the default PDOK layers MUST be seeded automatically

#### Scenario MAP-05b: Add a new layer

- GIVEN an admin clicks "Laag toevoegen"
- WHEN they fill in the form (title, layerType, url, attribution, isBaseLayer, isDefault, opacity, order)
- AND click "Opslaan"
- THEN a new `mapLayer` object MUST be created in OpenRegister
- AND the new layer MUST appear in the list immediately

#### Scenario MAP-05c: Set default base layer

- GIVEN multiple base layers are configured
- WHEN an admin sets `isDefault: true` on a layer
- THEN all other base layers MUST have `isDefault` set to false
- AND the map component MUST load that layer on initial render
