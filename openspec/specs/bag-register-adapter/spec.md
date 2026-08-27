# bag-register-adapter Specification

## Purpose
TBD - created by archiving change bag-register-adapter. Update Purpose after archive.
## Requirements
### Requirement: The BAG adapter MUST be selected by configuration, defaulting to no external calls

`BagAdapterInterface` SHALL offer a live adapter (`BagApiAdapter`) alongside a dormant default
(`LogBagAdapter`), selected by the `integration.bag.mode` app-config key via the existing
`IntegrationMode` resolver. When the mode is unset or unknown, the Log adapter SHALL be bound so no
external call to Kadaster is ever made unknowingly.

#### Scenario: Fresh install calls nothing external

- **GIVEN** a fresh dossiq install with no `integration.bag.mode` config
- **WHEN** any flow calls `BagAdapterInterface::lookupAddress()` or `::lookupObject()`
- **THEN** the Log adapter MUST handle it, return `LOOKUP_DEFERRED`, and no external request MUST
  leave the instance

#### Scenario: Admin flips BAG to the test tier

- **GIVEN** an admin sets `integration.bag.mode=test` with base URL and API key
- **WHEN** a lookup is made
- **THEN** the request MUST hit the configured Kadaster BAG API Individuele Bevragingen v2
  endpoint with the `X-Api-Key` header and `Accept-Crs: epsg:4326`

### Requirement: Address lookup MUST validate Dutch postcode format before any outbound call

`BagApiAdapter::lookupAddress()` SHALL validate the postcode against `^[1-9][0-9]{3}[A-Z]{2}$`
(case-insensitive input, normalized to uppercase) before making any HTTP request, returning
`INVALID_INPUT` on a malformed value.

#### Scenario: Malformed postcode is rejected without a network call

- **GIVEN** a caller supplies postcode `"0001AB"` (leading zero, invalid) or `"1234A"` (too short)
- **WHEN** `lookupAddress()` is called
- **THEN** the result MUST have `lookupStatus = INVALID_INPUT`
- **AND** no HTTP request MUST be made

#### Scenario: Valid postcode + huisnummer resolves an address

- **GIVEN** a live adapter bound to a fixture/test endpoint
- **WHEN** `lookupAddress('1234AB', '10')` is called and the endpoint returns a matching record
- **THEN** the result MUST have `lookupStatus = FOUND` with a normalized `address` envelope
  (`street`, `houseNumber`, `postcode`, `city`, `gebruiksdoel`, `oorspronkelijkBouwjaar`,
  `oppervlakte`, `geo` when present)

### Requirement: Object lookup MUST distinguish not-found from transport failure

`BagApiAdapter::lookupObject()` SHALL map a Kadaster HTTP 404 to `NOT_FOUND` and any other
transport/HTTP failure (5xx, network error) to `LOOKUP_ERROR`, never throwing into the caller —
mirroring the BRP/KvK fail-soft contract.

#### Scenario: Unknown pand id resolves NOT_FOUND, not an exception

- **GIVEN** a live adapter and a pand id that does not exist in the register
- **WHEN** `lookupObject('pand', '0000000000000000')` is called and Kadaster returns HTTP 404
- **THEN** the result MUST have `lookupStatus = NOT_FOUND`
- **AND** no exception MUST propagate to the caller

#### Scenario: Upstream 5xx degrades to LOOKUP_ERROR

- **GIVEN** a live adapter whose HTTP call throws (network error or Kadaster 5xx)
- **WHEN** `lookupObject()` or `lookupAddress()` is called
- **THEN** the result MUST have `lookupStatus = LOOKUP_ERROR` with `extras.reason =
  'transport-error'`
- **AND** the failure MUST be logged without leaking the API key

### Requirement: The normalization mapper MUST be a pure, independently-testable unit

`BagResponseMapper` SHALL contain zero I/O — it accepts a decoded Kadaster HAL+JSON fragment and
returns the normalized DTO array. All HTTP concerns (request building, headers, retries, error
mapping) SHALL live exclusively in `BagApiAdapter`.

#### Scenario: Partial record maps missing numeric fields to null, not zero-as-data

- **GIVEN** a Kadaster fragment missing `oorspronkelijkBouwjaar` and `oppervlakte`
- **WHEN** `BagResponseMapper::map()` normalizes it
- **THEN** both fields MUST be `null` in the output (distinguishable from a real `0`)
- **AND** `gebruiksdoel` MUST always be an array, even when the source has a single string value

### Requirement: The HTTP surface MUST expose graceful "not configured" responses, never a 500

`BagController` SHALL return the adapter's `lookupStatus` (including `LOOKUP_DEFERRED` when
dormant) as a 200 JSON body rather than surfacing an HTTP error for "not configured" — mirroring
how the internal BRP/KvK seams already degrade for their callers. HTTP error statuses are reserved
for request-shape problems (400) and unauthenticated access (401).

#### Scenario: Dormant BAG adapter still returns 200

- **GIVEN** `integration.bag.mode` is unset (Log adapter bound)
- **WHEN** an authenticated user calls `GET /api/external/bag/address?postcode=1234AB&huisnummer=10`
- **THEN** the response MUST be HTTP 200 with `lookupStatus = LOOKUP_DEFERRED` in the body

#### Scenario: Missing required query parameters is a 400

- **GIVEN** an authenticated user calls `GET /api/external/bag/address` with no `postcode` or
  `huisnummer`
- **WHEN** the request is handled
- **THEN** the response MUST be HTTP 400 with an error message identifying the missing parameters

#### Scenario: Unauthenticated access is rejected

- **GIVEN** no active user session
- **WHEN** any `bag#*` route is called
- **THEN** the response MUST be HTTP 401

