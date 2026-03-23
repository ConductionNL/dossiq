# PDOK Integration Specification

## Purpose

Integrate with PDOK (Publieke Dienstverlening Op de Kaart), the Dutch government's geodata platform, for base maps, address search (Locatieserver), and standard reference layers. PDOK services are free, require no API key, and are the standard geodata source for Dutch government applications.

**Standards**: PDOK API guidelines, BAG (Basisregistratie Adressen en Gebouwen), BRT (Basisregistratie Topografie)
**Feature tier**: V1

## Requirements

---

### REQ-PDOK-01: PDOK Tile Services (Base Maps)

**Feature tier**: V1

The system MUST use PDOK tile services as default base map layers.

#### Scenario PDOK-01a: BRT Achtergrondkaart

- GIVEN the map component is rendered
- WHEN no custom base layer is selected
- THEN the PDOK BRT Achtergrondkaart MUST be displayed
- AND tiles MUST be fetched from `https://service.pdok.nl/brt/achtergrondkaart/wmts/v2_0`
- AND the WMTS protocol MUST be used with the `EPSG:3857` tile matrix set

#### Scenario PDOK-01b: Luchtfoto base layer

- GIVEN the user switches to the "Luchtfoto" base layer
- WHEN the layer is selected
- THEN aerial imagery MUST be loaded from `https://service.pdok.nl/hwh/luchtfotorgb/wmts/v1_0`
- AND the imagery MUST be the most recent available vintage

#### Scenario PDOK-01c: Tile loading failure

- GIVEN PDOK tiles fail to load (network error, service maintenance)
- WHEN tiles return errors
- THEN the map MUST show a grey placeholder for failed tiles
- AND a subtle warning MUST appear: "Achtergrondkaart tijdelijk niet beschikbaar"
- AND the map MUST remain interactive (zoom, pan, markers still work)

---

### REQ-PDOK-02: Locatieserver Address Search

**Feature tier**: V1

The system MUST use the PDOK Locatieserver for address search and geocoding.

#### Scenario PDOK-02a: Suggest API for autocomplete

- GIVEN the user types in the location search field
- WHEN at least 3 characters are entered
- THEN the system MUST query `https://api.pdok.nl/bzk/locatieserver/search/v3_1/suggest`
- AND results MUST appear within 300ms (debounced at 200ms)
- AND each result MUST show: type icon (address/street/place), display name, and municipality

#### Scenario PDOK-02b: Lookup API for selected result

- GIVEN the user selects a suggestion "Keizersgracht 100, 1015 AA Amsterdam"
- WHEN the suggestion is selected
- THEN the system MUST call the Locatieserver lookup endpoint with the result ID
- AND extract the centroid geometry (WGS84)
- AND center the map on the location and place a marker

#### Scenario PDOK-02c: Free text search

- GIVEN the user types "gemeentehuis Tilburg" and presses Enter
- WHEN the free text search is triggered
- THEN the system MUST query the Locatieserver free endpoint
- AND display up to 10 results ranked by relevance
- AND each result MUST be clickable to navigate to the location

#### Scenario PDOK-02d: Reverse geocode

- GIVEN coordinates `[5.1214, 52.0907]`
- WHEN reverse geocoding is requested
- THEN the system MUST call the Locatieserver reverse endpoint
- AND return the nearest BAG address with street, house number, postcode, and city

---

### REQ-PDOK-03: BAG Data Display

**Feature tier**: V1

When viewing a case location, the system SHOULD display relevant BAG (building registry) information.

#### Scenario PDOK-03a: Show BAG data for address

- GIVEN a case at "Keizersgracht 100, Amsterdam"
- WHEN the user views the case location
- THEN the system MAY query the PDOK BAG WFS for the verblijfsobject
- AND display: bouwjaar (construction year), oppervlakte (area m2), gebruiksdoel (usage type), status

#### Scenario PDOK-03b: BAG building footprint

- GIVEN a case location near a building
- WHEN the BAG pand (building) layer is enabled
- THEN the building footprint polygon MUST be highlighted on the map
- AND clicking the footprint MUST show BAG pand properties
