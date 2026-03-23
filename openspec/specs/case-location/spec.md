# Case Location Specification

## Purpose

Enable location display and editing on cases. Cases already have a `geometry` field (GeoJSON string) in the OpenRegister schema. This spec defines the UI for viewing the case location on a map, picking a location when creating/editing a case, and searching for addresses via the PDOK Locatieserver (BAG geocoder).

**Standards**: GeoJSON (RFC 7946), PDOK Locatieserver API v3, BAG (Basisregistratie Adressen en Gebouwen)
**Feature tier**: V1

## Requirements

---

### REQ-LOC-01: Case Detail Map Tab

**Feature tier**: V1

The case detail view MUST display the case location on an interactive map when geometry data is available.

#### Scenario LOC-01a: Show location on case detail

- GIVEN case "Omgevingsvergunning Keizersgracht 100" with geometry `{"type": "Point", "coordinates": [4.8897, 52.3702]}`
- WHEN the user opens the case detail view
- THEN a "Locatie" tab MUST be visible in the case detail tabs
- AND the tab MUST show an interactive map centered on the case geometry
- AND the case location MUST be marked with a styled marker
- AND a sidebar panel MUST show the address (reverse geocoded from PDOK if available)

#### Scenario LOC-01b: No geometry available

- GIVEN case "Subsidieaanvraag 2026-001" with no geometry set
- WHEN the user opens the case detail view
- THEN the "Locatie" tab MUST still be visible
- AND the tab MUST show a placeholder message: "Geen locatie ingesteld"
- AND a "Locatie toevoegen" button MUST be displayed to open the location picker

#### Scenario LOC-01c: Polygon geometry display

- GIVEN a case with a polygon geometry (e.g., a building footprint or project area)
- WHEN the user views the Locatie tab
- THEN the polygon MUST be rendered on the map with fill and stroke
- AND the map MUST auto-fit to the polygon bounds with padding
- AND the area in square meters MUST be displayed in the sidebar

---

### REQ-LOC-02: Location Picker

**Feature tier**: V1

The system MUST provide a location picker for setting or updating the case geometry.

#### Scenario LOC-02a: Pick location by clicking map

- GIVEN the user clicks "Locatie toevoegen" or "Locatie wijzigen" on a case
- WHEN the location picker modal opens
- THEN the map MUST be displayed at the Netherlands center or the current case location
- AND the user MUST be able to click on the map to place a marker
- AND each click MUST update the coordinate display (latitude, longitude)
- AND a "Opslaan" button MUST save the point geometry to the case

#### Scenario LOC-02b: Draw polygon

- GIVEN the location picker is open
- WHEN the user selects the "Gebied tekenen" (draw area) tool
- THEN the user MUST be able to click points on the map to draw a polygon
- AND double-click MUST close the polygon
- AND the polygon area (m2) MUST be displayed
- AND "Opslaan" MUST save the polygon as GeoJSON geometry

#### Scenario LOC-02c: Search by address (PDOK Locatieserver)

- GIVEN the location picker is open
- WHEN the user types "Keizersgracht 100 Amsterdam" in the search field
- THEN the system MUST query the PDOK Locatieserver suggest API
- AND autocomplete suggestions MUST appear within 300ms
- AND selecting a suggestion MUST center the map on the address location
- AND place a marker at the geocoded coordinates

#### Scenario LOC-02d: Search by postcode

- GIVEN the location picker is open
- WHEN the user types "1015 AA" in the search field
- THEN the PDOK Locatieserver MUST return matching addresses
- AND results MUST show full address (straat, huisnummer, woonplaats)
- AND selecting a result MUST place a marker at the location

---

### REQ-LOC-03: Address Display and Reverse Geocoding

**Feature tier**: V1

The system MUST display a human-readable address for case locations using PDOK reverse geocoding.

#### Scenario LOC-03a: Reverse geocode point location

- GIVEN a case with geometry `{"type": "Point", "coordinates": [4.8897, 52.3702]}`
- WHEN the case detail Locatie tab is loaded
- THEN the system MUST call the PDOK Locatieserver reverse API
- AND display the nearest BAG address: "Keizersgracht 100, 1015 AA Amsterdam"
- AND cache the result to avoid repeated API calls

#### Scenario LOC-03b: Reverse geocode polygon centroid

- GIVEN a case with a polygon geometry
- WHEN the Locatie tab is loaded
- THEN the system MUST calculate the polygon centroid
- AND reverse geocode the centroid for a representative address
- AND label it as "Nabij: [address]" to indicate it is approximate

---

### REQ-LOC-04: Case Creation Location

**Feature tier**: V1

The case creation form MUST allow optional location selection.

#### Scenario LOC-04a: Set location during creation

- GIVEN the user is creating a new case
- WHEN the creation form is displayed
- THEN an optional "Locatie" section MUST be present
- AND the user MUST be able to search for an address or click on a map to set geometry
- AND the geometry MUST be saved with the case upon creation

#### Scenario LOC-04b: Case type requires location

- GIVEN a case type "Omgevingsvergunning" with `requiresLocation: true`
- WHEN the user tries to save a new case without geometry
- THEN validation MUST prevent saving
- AND display message: "Dit zaaktype vereist een locatie"
