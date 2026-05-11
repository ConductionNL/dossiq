<!-- ⚠️ EXTENSION NOTICE (auto-inserted by fix_extension_artifacts.py)
     Parent capability: case-management (Case Management)
     This spec extends the existing `case-management` capability. Do NOT define new entities or build new CRUD — reuse what `case-management` already provides. Your job is to add configuration, seed data, or workflow templates on top of that capability.
-->

## ADDED Requirements

### Requirement: Manifest-Driven Map Page (REQ-CMO-1)

The `/map` route SHALL be declared as a `type: 'map'` page in `src/manifest.json` and SHALL be rendered by `CnMapPage` from `@conduction/nextcloud-vue` (≥ beta.30). The hand-written `CaseMapView.vue` SHALL be removed.

**Feature tier**: V1
**Standards**: ADR-008 (manifest-driven views)

#### Scenario: Manifest declares map page

- **GIVEN** `src/manifest.json` defines page `CaseMap` with `route: "/map"`
- **WHEN** the manifest is loaded by the app shell
- **THEN** the page entry SHALL have `"type": "map"`
- **AND** the page entry SHALL NOT have a `"component"` key
- **AND** the page entry SHALL contain a `config` block with `register: "procest"`, `schema: "case"`, and `geometryField: "geometry"`
- **AND** the file `src/views/CaseMapView.vue` SHALL NOT exist in the repository

#### Scenario: Page renders via library component

- **GIVEN** a user navigates to `/map`
- **WHEN** the app shell resolves the route from `src/manifest.json`
- **THEN** the rendered component SHALL be `CnMapPage` from `@conduction/nextcloud-vue`
- **AND** a Leaflet map SHALL fill the main content area
- **AND** no console errors SHALL be emitted during mount

---

### Requirement: Case Marker Formatter (REQ-CMO-2)

A `caseMarkerFormatter` function SHALL project each case object to a marker descriptor `{ lat, lon, color, icon, popup, onClick }`. It SHALL handle Point geometries, Polygon geometries via centroid, and missing geometries.

**Feature tier**: V1
**Standards**: GeoJSON (RFC 7946)

#### Scenario: Format a Point geometry case

- **GIVEN** case `{ id: "c1", identifier: "Z-2026-001", title: "Klacht Vondelpark", status: "open", geometry: '{"type":"Point","coordinates":[4.8696,52.3580]}' }`
- **WHEN** `caseMarkerFormatter(case)` is called
- **THEN** the returned descriptor SHALL have `lat: 52.3580` and `lon: 4.8696`
- **AND** `popup.title` SHALL equal `"Klacht Vondelpark"` and `popup.subtitle` SHALL equal `"Z-2026-001"`
- **AND** `onClick` SHALL equal `{ route: "CaseDetail", params: { id: "c1" } }`

#### Scenario: Format a Polygon geometry case via centroid

- **GIVEN** a case with `geometry: '{"type":"Polygon","coordinates":[[[4.86,52.35],[4.88,52.35],[4.88,52.37],[4.86,52.37],[4.86,52.35]]]}'`
- **WHEN** `caseMarkerFormatter(case)` is called
- **THEN** the returned `lat` SHALL equal `52.36` (arithmetic mean of unique vertex lats, ±0.001 tolerance)
- **AND** the returned `lon` SHALL equal `4.87` (arithmetic mean of unique vertex lons, ±0.001 tolerance)

#### Scenario: Skip cases without geometry

- **GIVEN** a case with `geometry: null`, `geometry: ""`, or `geometry: "{}"`
- **WHEN** `caseMarkerFormatter(case)` is called
- **THEN** the function SHALL return `null`
- **AND** `CnMapPage` SHALL NOT render a marker for this case

---

### Requirement: Status-Coded Pin Palette (REQ-CMO-3)

Pin color and icon SHALL be derived from `case.status` using NL Design System CSS variable tokens. No hardcoded hex values SHALL appear in any palette code.

**Feature tier**: V1
**Standards**: NL Design System color tokens

#### Scenario: Open case gets info-blue pin

- **GIVEN** a case with `status: "open"`
- **WHEN** the marker is formatted
- **THEN** `color` SHALL equal `"var(--color-status-info)"`
- **AND** `icon` SHALL equal `"map-marker"`

#### Scenario: Blocked case gets error-red alert pin

- **GIVEN** a case with `status: "blocked"`
- **WHEN** the marker is formatted
- **THEN** `color` SHALL equal `"var(--color-status-error)"`
- **AND** `icon` SHALL equal `"alert-circle"`

#### Scenario: Closed case gets muted-grey check pin

- **GIVEN** a case with `status: "closed"`
- **WHEN** the marker is formatted
- **THEN** `color` SHALL equal `"var(--color-text-maxcontrast)"`
- **AND** `icon` SHALL equal `"check-circle"`

#### Scenario: No hardcoded hex values

- **GIVEN** the files `src/services/mapFormatters.js` and `src/services/caseStatusPalette.js`
- **WHEN** searched with regex `#[0-9A-Fa-f]{3,8}\b`
- **THEN** zero matches SHALL be found

---

### Requirement: Pin Click Navigates to Case Detail (REQ-CMO-4)

Clicking a pin SHALL navigate the user to the corresponding case detail page (`/cases/:id`) preserving the back-navigation stack and viewport state.

**Feature tier**: V1

#### Scenario: Pin click pushes case detail route

- **GIVEN** the map is rendered with a pin for case `id: "c1"`
- **WHEN** the user clicks the pin
- **THEN** Vue Router SHALL push `{ name: "CaseDetail", params: { id: "c1" } }`
- **AND** the browser URL SHALL become `/cases/c1`
- **AND** the case detail view SHALL mount and load the case object

#### Scenario: Back navigation returns to map with viewport preserved

- **GIVEN** the user navigated from `/map` (zoom 12, centered on Amsterdam) to `/cases/c1` via a pin click
- **WHEN** the user clicks the browser back button
- **THEN** the URL SHALL return to `/map`
- **AND** the map zoom SHALL be 12 (±0)
- **AND** the map center SHALL be Amsterdam (±0.001 lat/lon)

---

### Requirement: Filter Sidebar Wired to Pin Query (REQ-CMO-5)

A filter sidebar SHALL be available with the same filters as the `/cases` index page (`status`, `caseType`, `assignee`, `deadlineRange`). Changing any filter SHALL re-query the case set and re-render pins without resetting the map viewport.

**Feature tier**: V1

#### Scenario: Filter by status reduces pin set

- **GIVEN** the map renders 100 pins (50 open, 30 in_progress, 20 closed)
- **WHEN** the user selects `status = "open"` in the sidebar
- **THEN** the system SHALL re-query the OpenRegister `case` endpoint with `?status=open`
- **AND** exactly 50 pins SHALL remain visible
- **AND** all visible pins SHALL be info-blue per the status palette

#### Scenario: Filter change preserves viewport

- **GIVEN** the map is centered on Utrecht at zoom 14
- **WHEN** the user applies a filter `caseType = "Omgevingsvergunning"`
- **THEN** the map center SHALL remain Utrecht (±0.001 lat/lon)
- **AND** the map zoom SHALL remain 14 (±0)
- **AND** only pins matching `caseType = "Omgevingsvergunning"` SHALL be rendered

#### Scenario: Deadline range filter "due today"

- **GIVEN** the map renders cases with deadlines spanning the next 30 days
- **WHEN** the user selects `deadlineRange = "today"`
- **THEN** only pins whose case has a `deadline` field within today's calendar day SHALL be visible
- **AND** the sidebar result counter SHALL show `N van M cases` where `N` ≤ `M`

---

### Requirement: Clustering at Low Zoom (REQ-CMO-6)

Pins SHALL be clustered using `leaflet.markercluster` at zoom level < 14, and rendered individually at zoom ≥ 14. Cluster icon color SHALL reflect the most severe child status (priority: blocked > in_progress > open > closed).

**Feature tier**: V1

#### Scenario: Clusters appear at zoom 8

- **GIVEN** the map renders 500 pins spread across the Netherlands at zoom 8
- **WHEN** the page paints
- **THEN** individual pins SHALL NOT be visible
- **AND** cluster icons (numbered circles) SHALL be visible at major city locations

#### Scenario: Individual pins at zoom 14

- **GIVEN** the user zooms the map to level 14 (city block scale)
- **WHEN** the zoom-end event fires
- **THEN** cluster icons SHALL be replaced by individual pins
- **AND** each pin SHALL use the status palette from REQ-CMO-3

#### Scenario: Cluster color reflects most severe child

- **GIVEN** a cluster containing 3 open cases, 2 in_progress cases, and 1 blocked case
- **WHEN** the cluster icon renders
- **THEN** its color SHALL equal `var(--color-status-error)` (blocked wins per priority blocked > in_progress > open > closed)

---

### Requirement: Empty State (REQ-CMO-7)

When the filtered query returns zero matching cases (or zero cases with geometry), the map page SHALL render an `NcEmptyContent` overlay above the map.

**Feature tier**: V1
**Standards**: i18n (Dutch + English minimum)

#### Scenario: Empty state on zero filter matches

- **GIVEN** the filter combination `status = "blocked"` + `assignee = "alice"` matches zero cases
- **WHEN** the query returns an empty result set
- **THEN** the map SHALL display an `NcEmptyContent` overlay
- **AND** the overlay title SHALL be `"Geen zaken op de kaart"` (Dutch) or `"No cases on the map"` (English) based on user locale
- **AND** the body text SHALL suggest adjusting filters or adding locations

#### Scenario: Empty state when no cases have geometry

- **GIVEN** 50 cases exist matching the current filters, but all have `geometry: null`
- **WHEN** `caseMarkerFormatter` returns `null` for every case
- **THEN** the empty-state overlay SHALL be displayed
- **AND** the body text SHALL suggest "voeg locaties toe aan bestaande zaken" / "add locations to existing cases"

---

### Requirement: Performance Budget (REQ-CMO-8)

Initial map paint with 5,000 pins SHALL complete in under 2 seconds on baseline hardware (Chromium, mid-tier laptop). Result sets larger than 5,000 SHALL switch to viewport-bounded querying. Pin queries SHALL request only the fields needed for marker rendering.

**Feature tier**: V1

#### Scenario: 5,000 pins paint under 2 seconds

- **GIVEN** 5,000 synthetic cases with random geometry inside the Netherlands bbox exist in the register
- **WHEN** the user navigates to `/map` with no filters applied
- **THEN** the time from navigation-start to Largest Contentful Paint SHALL be less than 2000 ms (measured in Chrome DevTools Performance panel)
- **AND** all 5,000 pins SHALL be present in the marker cluster layer

#### Scenario: Viewport-bounded query above threshold

- **GIVEN** 10,000 cases match the current filters (above the 5,000 threshold)
- **WHEN** the map loads
- **THEN** `CnMapPage` SHALL send a `bbox` parameter to the OpenRegister query matching the current viewport
- **AND** only cases whose geometry falls within the bbox SHALL be returned (max ~2,000 per viewport at typical zoom)
- **AND** `moveend` events SHALL trigger a new bbox query
- **AND** the sidebar result counter SHALL show `N van 10000 cases` where `N` is the viewport-visible count

#### Scenario: Geometry-only field projection

- **GIVEN** any map query to the OpenRegister `case` endpoint
- **WHEN** the request is sent
- **THEN** the `fields` query parameter SHALL limit the projection to `id,title,identifier,status,geometry`
- **AND** the response payload SHALL NOT include unused fields such as `description`, `documents`, or `audit`
