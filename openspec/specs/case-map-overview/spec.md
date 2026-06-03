# Case Map Overview Specification

## Purpose

Provide an overview map showing all cases (or filtered subsets) plotted on a map with marker clustering, status-based coloring, and interactive filtering. This enables case handlers and managers to get a geographic overview of their workload and identify spatial patterns (e.g., clusters of meldingen in a neighborhood, VTH cases near a construction site).

**Standards**: GeoJSON (RFC 7946), PDOK tile services
**Feature tier**: V1

## Requirements

---

### REQ-OVERVIEW-01: Cases Map View

**Feature tier**: V1

The system MUST provide a map overview of all cases with geometry, accessible from the main navigation.

#### Scenario OVERVIEW-01a: Display all cases on map

- GIVEN 150 active cases with geometry data
- WHEN the user navigates to "Kaart" in the Procest navigation
- THEN a full-width map MUST be displayed showing all case locations
- AND cases MUST be rendered as markers or polygons depending on geometry type
- AND marker clustering MUST be active at low zoom levels
- AND the map MUST auto-fit to show all case markers

#### Scenario OVERVIEW-01b: Case popup on marker click

- GIVEN cases are displayed on the overview map
- WHEN the user clicks a case marker
- THEN a popup MUST show: case title, identifier, status (colored badge), assignee, and case type
- AND the popup MUST include a "Bekijk zaak" link that navigates to the case detail view

#### Scenario OVERVIEW-01c: Status-based marker coloring

- GIVEN cases with different statuses
- WHEN displayed on the overview map
- THEN markers MUST be color-coded by status category:
  - Green: completed/closed cases
  - Blue: active/in-progress cases
  - Orange: cases nearing deadline (within 5 days)
  - Red: overdue cases
- AND the legend MUST be visible in the map corner

---

### REQ-OVERVIEW-02: Map Filters

**Feature tier**: V1

The overview map MUST support filtering cases by standard attributes.

#### Scenario OVERVIEW-02a: Filter by case type

- GIVEN the overview map with cases of types "Omgevingsvergunning", "Klacht", "Subsidie"
- WHEN the user selects "Omgevingsvergunning" in the case type filter
- THEN only omgevingsvergunning cases MUST be displayed on the map
- AND the marker count MUST update
- AND cluster counts MUST recalculate

#### Scenario OVERVIEW-02b: Filter by status

- GIVEN the overview map showing all cases
- WHEN the user toggles off "Afgerond" (completed) in the status filter
- THEN completed cases MUST be hidden from the map
- AND remaining markers MUST re-cluster

#### Scenario OVERVIEW-02c: Filter by assignee

- GIVEN the overview map
- WHEN the user selects "Mijn zaken" filter
- THEN only cases assigned to the current user MUST be displayed

#### Scenario OVERVIEW-02d: Combined filters

- GIVEN multiple filters active: case type "Klacht" AND status "Open"
- WHEN both filters are applied
- THEN only open klacht cases MUST be displayed on the map
- AND a filter summary badge MUST show "2 filters actief"

---

### REQ-OVERVIEW-03: Spatial Selection

**Feature tier**: V1

The overview map MUST support selecting cases by geographic area.

#### Scenario OVERVIEW-03a: Rectangle selection

- GIVEN the overview map with cases
- WHEN the user activates the "Gebied selecteren" tool and draws a rectangle on the map
- THEN all cases within the rectangle MUST be highlighted
- AND a sidebar MUST appear listing the selected cases
- AND the sidebar MUST show count and allow bulk actions (if user has permission)

#### Scenario OVERVIEW-03b: Polygon selection

- GIVEN the "Gebied selecteren" tool is active
- WHEN the user draws a freeform polygon by clicking points
- THEN cases within the polygon MUST be selected using point-in-polygon calculation
- AND polygon area (m2/ha) MUST be displayed

#### Scenario OVERVIEW-03c: Wijk/buurt selection

- GIVEN the CBS wijken/buurten WFS layer is configured
- WHEN the user clicks on a wijk polygon on the map
- THEN all cases within that wijk MUST be selected
- AND the wijk name and code MUST be displayed in the sidebar header

---

### REQ-OVERVIEW-04: Dashboard Map Widget

**Feature tier**: V1

The Procest dashboard MUST support an optional map widget showing case locations.

#### Scenario OVERVIEW-04a: Map widget on dashboard

- GIVEN the dashboard is configured to show the map widget
- WHEN the dashboard loads
- THEN a compact map MUST be rendered showing the user's assigned cases
- AND the map MUST use marker clustering
- AND clicking a marker popup "Bekijk zaak" link MUST navigate to the case detail

#### Scenario OVERVIEW-04b: Widget size

- GIVEN the map widget is on the dashboard
- WHEN rendered
- THEN the widget MUST be 400px minimum height
- AND the widget MUST respect the Nextcloud dashboard grid layout
