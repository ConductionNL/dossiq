---
status: draft
---
# case-location Specification

## Purpose

Enable location display and editing on cases. Cases already have a `geometry` field (GeoJSON string) in the OpenRegister schema. This spec defines the UI for viewing the case location on an interactive map, picking a location when creating or editing a case, and searching for addresses via the PDOK Locatieserver (BAG geocoder).

**Standards**: GeoJSON (RFC 7946), PDOK Locatieserver API v3, BAG (Basisregistratie Adressen en Gebouwen)
**Feature tier**: V1

## Context

Cases in Procest carry a `geometry` field (GeoJSON string), but there is no UI to view or edit it. This blocks location-based workflows — particularly VTH (vergunningen, toezicht, handhaving) — where the precise address or parcel of a case is essential for processing. Tender analysis shows 1,300+ requirements across GIS/geo-viewer, PDOK integration, and location indication clusters, with ~300 unique tenders demanding integrated map capabilities in a case management system.

The `case.geometry` field already exists; this change adds the UI layer (a "Locatie" tab in case detail, a location picker modal, PDOK address search, and optional location section in case creation) to make it usable.

No new backend controllers or OpenRegister schemas are introduced. The only data model change is adding an optional `requiresLocation` boolean to `caseType` (non-breaking).

## Requirements

---

### REQ-LOC-01: Case Detail Map Tab

**Feature tier**: V1

The case detail view MUST display the case location on an interactive map when geometry data is available.

#### Scenario LOC-01a: Show location on case detail

- GIVEN case "Omgevingsvergunning Keizersgracht 100" with geometry `{"type": "Point", "coordinates": [4.8897, 52.3702]}`
- WHEN the user opens the case detail view
- THEN a "Locatie" tab MUST be visible in the case detail tabs
- AND the tab MUST show an interactive Leaflet map centered on the case geometry at zoom level 16
- AND the case location MUST be marked with a styled marker
- AND a sidebar panel MUST show the address (reverse geocoded from PDOK Locatieserver if available)

#### Scenario LOC-01b: No geometry available

- GIVEN case "Subsidieaanvraag 2026-001" with no geometry set
- WHEN the user opens the case detail view
- THEN the "Locatie" tab MUST still be visible
- AND the tab MUST show a placeholder message: "Geen locatie ingesteld"
- AND a "Locatie toevoegen" `NcButton` MUST be displayed to open the location picker

#### Scenario LOC-01c: Polygon geometry display

- GIVEN a case with a polygon geometry (e.g., a building footprint or project area)
- WHEN the user views the Locatie tab
- THEN the polygon MUST be rendered on the map with fill and stroke using NL Design System color tokens
- AND the map MUST auto-fit to the polygon bounds using `fitBounds()` with 20px padding
- AND the area in square meters MUST be displayed in the sidebar panel

---

### REQ-LOC-02: Location Picker

**Feature tier**: V1

The system MUST provide a location picker for setting or updating the case geometry.

#### Scenario LOC-02a: Pick location by clicking map

- GIVEN the user clicks "Locatie toevoegen" or "Locatie wijzigen" on a case
- WHEN the location picker modal opens
- THEN the map MUST be displayed at the Netherlands center (52.1551744, 5.3850548) or the current case location if geometry is already set
- AND the user MUST be able to click on the map to place a marker
- AND each click MUST update the coordinate display showing latitude and longitude
- AND a "Opslaan" button MUST save the point geometry to the case via `caseStore.saveObject()`

#### Scenario LOC-02b: Draw polygon

- GIVEN the location picker is open
- WHEN the user selects the "Gebied tekenen" (draw area) tool via the Leaflet Draw toolbar
- THEN the user MUST be able to click points on the map to draw a polygon
- AND double-click MUST close the polygon
- AND the polygon area (m²) MUST be displayed below the map
- AND "Opslaan" MUST save the polygon as a GeoJSON `Polygon` geometry to `case.geometry`

#### Scenario LOC-02c: Search by address (PDOK Locatieserver)

- GIVEN the location picker is open
- WHEN the user types "Keizersgracht 100 Amsterdam" in the search field
- THEN the system MUST query the PDOK Locatieserver suggest API (`/v3_1/suggest?q={query}&rows=5`) with debounce of 300ms
- AND autocomplete suggestions MUST appear within 300ms of the debounce timeout
- AND selecting a suggestion MUST center the map on the address location
- AND place a marker at the geocoded coordinates from the `centroide_ll` WKT field

#### Scenario LOC-02d: Search by postcode

- GIVEN the location picker is open
- WHEN the user types "1015 AA" in the search field
- THEN the PDOK Locatieserver MUST return matching addresses
- AND results MUST show full address (`weergavenaam`: straat, huisnummer, woonplaats)
- AND selecting a result MUST place a marker at the geocoded location

---

### REQ-LOC-03: Address Display and Reverse Geocoding

**Feature tier**: V1

The system MUST display a human-readable address for case locations using PDOK reverse geocoding.

#### Scenario LOC-03a: Reverse geocode point location

- GIVEN a case with geometry `{"type": "Point", "coordinates": [4.8897, 52.3702]}`
- WHEN the case detail Locatie tab is loaded
- THEN the system MUST call the PDOK Locatieserver reverse API (`/v3_1/reverse?lat={lat}&lon={lon}&rows=1`)
- AND display the `weergavenaam` of the nearest BAG address: "Keizersgracht 100, 1015 AA Amsterdam"
- AND cache the result in component data for the tab lifetime to avoid repeated API calls

#### Scenario LOC-03b: Reverse geocode polygon centroid

- GIVEN a case with a polygon geometry
- WHEN the Locatie tab is loaded
- THEN the system MUST calculate the polygon centroid using arithmetic mean of vertex coordinates
- AND reverse geocode the centroid via the PDOK Locatieserver reverse API
- AND label the result as "Nabij: [weergavenaam]" to indicate it is approximate

---

### REQ-LOC-04: Case Creation Location

**Feature tier**: V1

The case creation form MUST allow optional location selection.

#### Scenario LOC-04a: Set location during creation

- GIVEN the user is creating a new case
- WHEN the creation form is displayed
- THEN an optional "Locatie" section MUST be present in the form
- AND the user MUST be able to search for an address via PDOK or click on a map to set geometry
- AND the selected geometry MUST be included in the case payload on save

#### Scenario LOC-04b: Case type requires location

- GIVEN a case type "Omgevingsvergunning" with `requiresLocation: true`
- WHEN the user tries to save a new case without geometry set
- THEN client-side validation MUST prevent saving
- AND display an `NcDialog` with message: "Dit zaaktype vereist een locatie"
- AND focus MUST return to the location section so the user can set a location

---

## Dependencies

- **PDOK Locatieserver v3** — address autocomplete (`/suggest`) and reverse geocoding (`/reverse`); public API, CORS-enabled, no authentication required
- **Leaflet** (npm: `leaflet`) — map rendering library for point and polygon display
- **leaflet-draw** (npm: `leaflet-draw`) — polygon drawing tool in the location picker
- **OpenRegister ObjectService** — existing `saveObject($register, $schema, $object)` used to persist `case.geometry`
- **case entity** — `geometry` field (GeoJSON string) already exists in ADR-000
- **caseType entity** — `requiresLocation` boolean field added as optional non-breaking extension

---

## Implementation Status

**Not yet implemented.** No location-related Vue components exist in the Procest codebase. The `case.geometry` field exists in the data model but has no UI.

**Foundation available:**

- `case.geometry` (GeoJSON string) is defined in the ADR-000 data model and `procest_register.json`
- `mapLayer` entity already exists in the data model for GIS layer configuration
- `createObjectStore('case')` provides `saveObject` for persisting geometry changes
- `NcDialog`, `NcButton`, `NcTextField` available from `@conduction/nextcloud-vue`

**Partial implementations:** None.

---

## Standards & References

- **GeoJSON (RFC 7946)** — geometry storage format; `case.geometry` stores a serialized GeoJSON object
- **PDOK Locatieserver v3** — Dutch national geocoding service backed by BAG; endpoints: `suggest`, `reverse`
- **BAG (Basisregistratie Adressen en Gebouwen)** — authoritative address registry underlying PDOK results
- **Leaflet** — lightweight map library used across Dutch government geoportals (PDOK viewer, NLMaps)
- **NL Design System** — all UI components and colors MUST use NL Design System CSS custom properties
- **WCAG AA** — map interactions MUST be keyboard-accessible; address search results MUST be announced to screen readers via `aria-live`
- **CMMN 1.1** — location is a case attribute (`case.geometry`), not a separate CMMN element
