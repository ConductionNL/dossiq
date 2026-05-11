## ADDED Requirements

### Requirement: REQ-PDOK-1 Centralised Locatieserver Service

The system SHALL provide a single `PdokLocatieserverService` that wraps the PDOK Locatieserver v3_1 endpoints `/suggest`, `/free`, `/lookup`, and `/reverse`. The service SHALL be the sole ingress for every Locatieserver call in Procest; no other class may instantiate an HTTP client targeting the Locatieserver host. When the `pdok_locatieserver_source` IAppConfig key is non-empty, outbound HTTP SHALL be dispatched through the configured OpenConnector source. Each call SHALL emit a structured log line containing method, cache hit/miss, and elapsed milliseconds.

**Feature tier**: V1

#### Scenario: Suggest call routed through OpenConnector when source is configured

- **GIVEN** the admin has set `pdok_locatieserver_source` to a valid OpenConnector source slug
- **WHEN** a consumer invokes `PdokLocatieserverService::suggest("Keizersgracht")`
- **THEN** the outbound HTTP request SHALL be dispatched through OpenConnector
- **AND** the Locatieserver public hostname SHALL NOT appear in the Procest container's outbound HTTP audit
- **AND** the response SHALL be a non-empty result list

#### Scenario: No other class may bypass the service

- **WHEN** the codebase is scanned for direct references to the Locatieserver public hostname outside `lib/Service/Pdok/`
- **THEN** zero matches SHALL be found
- **AND** the legacy `PdokLocatieserverClient` class from the case-location change SHALL no longer exist in the tree

### Requirement: REQ-PDOK-2 BAG Service

The system SHALL provide a `PdokBagService` exposing `getNummeraanduiding(id)`, `getVerblijfsobject(id)`, and `getPand(id)` against the PDOK BAG WFS v2_0. Responses SHALL be normalised to a stable Procest-internal shape: snake_case fields converted to camelCase, `bouwjaar` always an integer, `oppervlakte` always an integer expressed in square metres, and `gebruiksdoel` always an array (even when the upstream returns a single string). When `pdok_bag_source` is non-empty, outbound HTTP SHALL be routed through OpenConnector.

**Feature tier**: V1

#### Scenario: Nummeraanduiding lookup returns normalised shape

- **GIVEN** a known nummeraanduiding id `0363200000406567`
- **WHEN** `PdokBagService::getNummeraanduiding("0363200000406567")` is called
- **THEN** the response SHALL contain `bouwjaar` as an integer
- **AND** `oppervlakte` SHALL be an integer expressed in square metres
- **AND** `gebruiksdoel` SHALL be an array (length ≥ 1)
- **AND** all field names SHALL be camelCase

#### Scenario: Unknown nummeraanduiding surfaces as null

- **GIVEN** a malformed or unknown nummeraanduiding id `9999999999999999`
- **WHEN** the service is queried
- **THEN** the service SHALL return `null` (not throw) so that the LocationService can translate it into a 422 with `nummeraanduidingId.not-found`

### Requirement: REQ-PDOK-3 Default Basemap Configuration

The system SHALL register, on app install and update, exactly four default `MapLayer` rows: "BRT Achtergrondkaart", "BRT Achtergrondkaart Grijs", "Luchtfoto", and "NL Design System". "BRT Achtergrondkaart" SHALL be `isDefault = true` when the active theme is the standard Nextcloud theme; "NL Design System" SHALL be `isDefault = true` only when the nldesign theme is active. The repair step SHALL be idempotent: re-running it SHALL NOT create duplicate rows and SHALL NOT overwrite admin-edited title, attribution, or order fields.

**Feature tier**: V1

#### Scenario: First install seeds four rows with the correct default

- **GIVEN** a fresh Procest install with the standard theme active
- **WHEN** `SeedPdokBasemaps::run()` executes
- **THEN** exactly four `MapLayer` rows SHALL exist with slugs prefixed `pdok-`
- **AND** the row "BRT Achtergrondkaart" SHALL have `isDefault = true`
- **AND** the other three rows SHALL have `isDefault = false`

#### Scenario: Repair step preserves admin edits

- **GIVEN** an admin has renamed "BRT Achtergrondkaart" to "Topografische kaart" and changed its display order
- **WHEN** `SeedPdokBasemaps::run()` executes for the second time
- **THEN** the row count SHALL remain four
- **AND** the renamed title and adjusted order SHALL be preserved unchanged

### Requirement: REQ-PDOK-4 Admin Settings Page

The system SHALL expose a "PDOK" tab in the Procest admin settings that surfaces every key documented in `design.md` §PDOK config: three endpoint URLs, three OpenConnector source slugs, two cache TTLs, one rate-limit ceiling, and two outage banner copies (nl + en). Endpoint URLs without a scheme or host SHALL be rejected both client-side and server-side. A "Test verbinding" button per service SHALL call the corresponding `/api/pdok/health` route and surface ok/degraded.

**Feature tier**: V1

#### Scenario: Invalid endpoint URL is rejected server-side

- **GIVEN** an admin submits `pdok_locatieserver_endpoint = "not a url"`
- **WHEN** the settings controller validates the payload
- **THEN** the response SHALL be HTTP 422 with error code `endpoint.invalid-url`
- **AND** the existing value SHALL remain unchanged in IAppConfig

#### Scenario: Test verbinding reflects degraded state

- **GIVEN** the Locatieserver service is currently in degraded state
- **WHEN** the admin clicks "Test verbinding" for Locatieserver
- **THEN** the UI SHALL display `degraded` together with the configured outage banner copy

### Requirement: REQ-PDOK-5 Case-Location Consumer Integration

The `LocationService` from the `case-location` change SHALL consume `PdokLocatieserverService` via constructor injection. The legacy `PdokLocatieserverClient` class SHALL be removed from the tree as part of this change. All REQ-CL-3 scenarios from `case-location` SHALL keep passing through the new shared service without any change to their wording.

**Feature tier**: V1

#### Scenario: LocationService delegates suggest to the shared service

- **GIVEN** a consumer of `LocationService` invokes the suggest path used by the case-location autocomplete
- **WHEN** the call is dispatched
- **THEN** the only HTTP egress SHALL originate from `PdokLocatieserverService` (or its OpenConnector replacement)
- **AND** no PDOK URL SHALL be referenced inside `lib/Service/LocationService.php`

#### Scenario: Reverse-geocode path still satisfies REQ-CL-5

- **GIVEN** a payload `{case, source: "pdok-reverse", latitude: 52.3702, longitude: 4.8897}`
- **WHEN** the LocationService saves the location
- **THEN** `formattedAddress` and (when within 25 m) `nummeraanduidingId` SHALL be populated by the response of `PdokLocatieserverService::reverse(...)`
- **AND** the saved row SHALL match the REQ-CL-5 expectations from the case-location spec

### Requirement: REQ-PDOK-6 Shared Cache Layer

The system SHALL provide an APCu-backed cache shared across all PDOK services. Cache TTLs SHALL be configurable via IAppConfig: `pdok_cache_lookup_ttl_seconds` (default 86400) for lookup, reverse, BAG, and Kadaster responses; `pdok_cache_suggest_ttl_seconds` (default 300) for suggest. Cache keys SHALL include the full request signature including filter parameters so that filtered and unfiltered requests do not collide. The cache SHALL be purgeable from the admin settings tab with a single action.

**Feature tier**: V1

#### Scenario: BAG lookup is served from cache on the second call

- **GIVEN** a fresh APCu cache and a call to `GET /api/pdok/bag/nummeraanduiding/0363200000406567`
- **WHEN** a second call for the same id arrives within 24 hours
- **THEN** the second response SHALL carry the header `X-Pdok-Cache: hit`
- **AND** the upstream BAG WFS audit SHALL show exactly one request for the id

#### Scenario: Filtered suggest does not collide with unfiltered suggest

- **GIVEN** an unfiltered cached response for `suggest(q = "Keizersgracht")`
- **WHEN** a filtered call `suggest(q = "Keizersgracht", fq = "type:adres")` arrives
- **THEN** the filtered call SHALL not be served from the unfiltered cache entry
- **AND** the filtered response SHALL be persisted under its own key

### Requirement: REQ-PDOK-7 Outage Handling

When the Locatieserver service receives three or more 5xx responses within a rolling 60 second window, it SHALL enter a `degraded` state for at least 5 minutes. During the degraded window, `GET /api/pdok/health` SHALL report `degraded`, the map component SHALL show the configured outage banner, and `GET /api/pdok/suggest` SHALL short-circuit to an empty array so consumers can still capture user-typed input. The service SHALL self-clear the `degraded` state on the first successful response after the cool-down.

**Feature tier**: V1

#### Scenario: Three upstream 5xx responses flip the service to degraded

- **GIVEN** the service is currently in `ok` state
- **WHEN** three consecutive Locatieserver calls return HTTP 503 within a 30 second span
- **THEN** `GET /api/pdok/health` SHALL report `degraded` for the next 5 minutes
- **AND** `GET /api/pdok/suggest?q=Keizersgracht` SHALL return an empty array during that window

#### Scenario: Free-text save path remains usable during outage

- **GIVEN** the Locatieserver service is in `degraded` state
- **WHEN** a user saves a case-location with a typed address but no PDOK match
- **THEN** the LocationService SHALL accept the row with `source = free`
- **AND** the row SHALL persist with `nummeraanduidingId = null`

### Requirement: REQ-PDOK-8 Rate Limiting

The system SHALL apply a per-service token-bucket rate limiter at the ceiling configured by `pdok_rate_ceiling_rps` (default 10 requests per second). Cache hits SHALL bypass the limiter. When the limiter denies a call and no cached fallback is available, the response SHALL be HTTP 429 with error code `pdok.rate-limited` and a `Retry-After` header.

**Feature tier**: V1

#### Scenario: Rate ceiling enforced on uncached traffic

- **GIVEN** `pdok_rate_ceiling_rps = 5`
- **AND** an empty cache
- **WHEN** ten unique suggest calls arrive within a single second
- **THEN** at least five calls SHALL succeed
- **AND** the denied calls SHALL respond with HTTP 429, error code `pdok.rate-limited`, and a `Retry-After` header

#### Scenario: Cache hits bypass the limiter

- **GIVEN** `pdok_rate_ceiling_rps = 1`
- **AND** a cached suggest response for `q = "Keizersgracht"`
- **WHEN** 50 identical suggest calls arrive within a single second
- **THEN** every call SHALL succeed with HTTP 200
- **AND** zero calls SHALL be denied by the limiter
