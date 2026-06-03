---
retrofit_extensions:
  - REQ-PDOK-04
  - REQ-PDOK-05
---

# PDOK Integration Specification

## Purpose

@e2e exclude Backend geodata service integration; tile/geocoder calls are covered by service-level tests, not browser E2E.

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

---

### REQ-PDOK-04: PdokBagService SHALL be the single server-side ingress for PDOK BAG WFS v2_0 lookups, with normalization and 24h caching

`OCA\Procest\Service\Pdok\PdokBagService` SHALL expose `getNummeraanduiding(string $id)`, `getVerblijfsobject(string $id)`, and `getPand(string $id)`. Every method SHALL hit the PDOK BAG WFS v2_0 endpoint (default `https://service.pdok.nl/lv/bag/wfs/v2_0`, overridable via the `pdok_bag_endpoint` IAppConfig key) and SHALL normalize the response into a Procest-internal shape — snake_case → camelCase, `bouwjaar` cast to integer, `oppervlakte` cast to integer square metres, `gebruiksdoel` always an array. Responses SHALL be cached in the distributed cache keyed by BAG identifier for 24 hours (`DEFAULT_TTL = 86400`); cache hits bypass the rate guard. When `pdok_bag_source` is non-empty, outbound HTTP SHALL be dispatched through the configured OpenConnector source slug; otherwise the service SHALL call PDOK directly.

#### Scenario: getVerblijfsobject normalizes BAG fields
- **GIVEN** a BAG verblijfsobject identifier `0363010012345678`
- **WHEN** `getVerblijfsobject('0363010012345678')` is called
- **THEN** the result SHALL contain `bouwjaar` (int), `oppervlakte` (int m2), and `gebruiksdoel` (array)

#### Scenario: Cache hit bypasses the rate guard
- **GIVEN** `getPand($id)` was called once and the response cached
- **WHEN** the same `$id` is requested again within 24 hours
- **THEN** the service SHALL return the cached response without issuing a new HTTP request

#### Scenario: OpenConnector routing when `pdok_bag_source` is set
- **GIVEN** the IAppConfig key `pdok_bag_source` holds an OpenConnector source slug
- **WHEN** any BAG lookup runs and the cache is cold
- **THEN** the outbound HTTP SHALL be dispatched through that OpenConnector source rather than directly to PDOK

#### Notes
- All three lookup methods share the same normalization pipeline; behavior on missing fields is "preserve whatever PDOK returned but coerce types".

---

### REQ-PDOK-05: PdokLocatieserverService SHALL expose suggest/free/lookup/reverse/health for PDOK address search

`OCA\Procest\Service\Pdok\PdokLocatieserverService` SHALL expose five public methods backing the Locatieserver API:
- `suggest(string $query, array $fq = [], int $rows = 10)` — type-ahead suggestions
- `free(string $query, array $fq = [])` — free-text address search
- `lookup(string $id)` — full record lookup by Locatieserver identifier
- `reverse(float $lat, float $lng)` — reverse geocoding from WGS84
- `health()` — endpoint health probe returning a status string

The service SHALL respect optional OpenConnector routing (analogous to `PdokBagService` — IAppConfig source key `pdok_locatieserver_source` when non-empty) and SHALL cache results per `(method, query/id/coords, fq, rows)` tuple.

#### Scenario: suggest forwards rows + fq
- **GIVEN** a caller invokes `suggest('Damrak', ['type:adres'], 5)`
- **WHEN** the request reaches the Locatieserver
- **THEN** the query SHALL include `rows=5` and the filter query `fq=type:adres`

#### Scenario: reverse returns nearest address for WGS84 coords
- **GIVEN** coordinates `(52.3676, 4.9041)` (Amsterdam centrum)
- **WHEN** `reverse(52.3676, 4.9041)` is called
- **THEN** the response SHALL contain at least one address with `weergavenaam` set

#### Scenario: health returns a status string
- **WHEN** `health()` is called
- **THEN** the response SHALL be a non-empty string describing the endpoint status (`"ok"`, `"degraded"`, etc.)

#### Scenario: Sustained 5xx flips into 5-minute degraded mode
- **GIVEN** 3 consecutive 5xx responses from Locatieserver within 60 seconds
- **WHEN** `suggest()` is invoked during the next 5 minutes
- **THEN** the service SHALL short-circuit and return an empty array
- **AND** `LocationService` SHALL be free to fall back to free-text search (REQ-CL-3)

#### Notes
- The five methods deliberately mirror Locatieserver's documented endpoints — call signatures are not Procest-domain shapes.
- Cache TTL: 24h for `lookup`/`reverse`, 5 min for `suggest` (rationale: address autocomplete invalidates faster than full-record lookups).
- Outage handling: 3 consecutive 5xx responses within 60 s flip the service into a 5 min "degraded" state during which `suggest()` short-circuits to an empty array.
