---
retrofit_extensions:
  - REQ-LOC-05
  - REQ-LOC-06
---

# Case Location Specification

## Purpose

Enable location display and editing on cases. Cases already have a `geometry` field (GeoJSON string) in the OpenRegister schema. This spec defines the UI for viewing the case location on a map, picking a location when creating/editing a case, and searching for addresses via the PDOK Locatieserver (BAG geocoder).

**Standards**: GeoJSON (RFC 7946), PDOK Locatieserver API v3, BAG (Basisregistratie Adressen en Gebouwen)
**Feature tier**: V1

## OR Capability Citations

This spec consumes the following OpenRegister capabilities (per
ADR-022, procest-adopt-or-abstractions):

- `geo-metadata-kaart` — geo metadata is annotation-driven on the case
  schema, not a custom location service. See
  `openregister/openspec/changes/geo-metadata-kaart/`.

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

---

### REQ-LOC-05: LocationService SHALL validate every location payload against the per-source rule matrix and the universal anchor rule

`OCA\Procest\Service\LocationService::validate(array $payload): array` SHALL return an array of error codes (empty = valid) and SHALL enforce the following rules:
- `source` SHALL be present and SHALL be one of `bag` / `pdok-reverse` / `gps` / `free` (else `source.required` / `source.invalid`).
- `case` SHALL be present (else `case.required`).
- `source=bag` requires `nummeraanduidingId` (else `nummeraanduidingId.required`).
- `source=pdok-reverse` requires `latitude` + `longitude` (else `latitude-longitude.required`).
- `source=gps` requires `latitude` + `longitude` + `accuracyRadius` (else `latitude-longitude.required` and/or `accuracyRadius.required`).
- `source=free` requires at least one of `formattedAddress` OR (`latitude` + `longitude`) (else `formattedAddress-or-coordinates.required`).
- Universal anchor rule: every location SHALL carry either `nummeraanduidingId` OR (`latitude` + `longitude`); otherwise emit `bag-or-coordinates.required`.

#### Scenario: BAG source without nummeraanduidingId fails
- **GIVEN** payload `{source: 'bag', case: 'uuid-x'}`
- **WHEN** `validate()` is called
- **THEN** the result SHALL contain `nummeraanduidingId.required` AND `bag-or-coordinates.required`

#### Scenario: GPS source without accuracyRadius fails
- **GIVEN** payload `{source: 'gps', case: 'uuid-x', latitude: 52.0, longitude: 5.0}`
- **WHEN** `validate()` is called
- **THEN** the result SHALL contain `accuracyRadius.required`

#### Scenario: Valid pdok-reverse payload passes
- **GIVEN** payload `{source: 'pdok-reverse', case: 'uuid-x', latitude: 52.0, longitude: 5.0, nummeraanduidingId: '0363010012345678'}`
- **WHEN** `validate()` is called
- **THEN** the result SHALL be an empty array

#### Scenario: Universal anchor rule fires even when source-specific rules pass
- **GIVEN** payload `{source: 'free', case: 'uuid-x', formattedAddress: 'Damrak 1, Amsterdam'}` (no nummeraanduidingId, no coords)
- **WHEN** `validate()` is called
- **THEN** the result SHALL contain `bag-or-coordinates.required`

---

### REQ-LOC-06: LocationService::attachToCase SHALL persist a validated location to OpenRegister and SHALL surface failure modes as explicit exceptions or null

`OCA\Procest\Service\LocationService::attachToCase(string $caseId, array $location): ?array` SHALL:
- Throw `\RuntimeException('caseId is required')` when `$caseId === ''`.
- Inject the caseId into the payload, call `validate()`, and throw `\RuntimeException('Location payload failed validation: <codes>')` if any errors are returned.
- Resolve the ObjectService via `SettingsService::getObjectService()`; if null, throw `\RuntimeException('OpenRegister is not available')`.
- Read the `register` + `location_schema` IAppConfig keys; if either is empty, throw `\RuntimeException('Location schema is not configured')`.
- Call `objectService->saveObject($register, $schema, $payload)` and return the persisted record. On `\Throwable` during save, log an error including the caseId + APP_ID and return `null` (NOT propagate).
- Normalize the returned object: array passthrough, or `jsonSerialize()` on an object with that method, else `null`.

#### Scenario: Empty caseId throws
- **WHEN** `attachToCase('', ['source' => 'bag', ...])` is called
- **THEN** the method SHALL throw `\RuntimeException('caseId is required')`

#### Scenario: Validation failure throws with all error codes
- **GIVEN** an invalid payload (source missing, no coords/BAG)
- **WHEN** `attachToCase('uuid-x', $bad)` is called
- **THEN** the method SHALL throw `\RuntimeException` whose message starts with `Location payload failed validation:` and contains every emitted error code

#### Scenario: OpenRegister save failure returns null
- **GIVEN** valid payload + configured schema
- **AND** `ObjectService::saveObject()` raises `\Throwable`
- **WHEN** `attachToCase('uuid-x', $valid)` is called
- **THEN** the method SHALL log an error with the caseId
- **AND** SHALL return `null` (NOT propagate the throwable)

#### Notes
- The "swallow Throwable + return null on save error" pattern is observed but worth flagging: callers that need to surface persistence failures must defend against null rather than rely on exceptions.
- ADR-022: this method is the canonical write path for case locations; controllers must not bypass it to call `ObjectService::saveObject` directly.
