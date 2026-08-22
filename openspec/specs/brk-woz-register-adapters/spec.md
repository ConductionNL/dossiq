# brk-woz-register-adapters Specification

## Purpose
TBD - created by archiving change brk-woz-register-adapters. Update Purpose after archive.
## Requirements
### Requirement: The BRK adapter MUST be selected by configuration, defaulting to no external calls

`BrkAdapterInterface` SHALL offer a live adapter (`BrkApiAdapter`) alongside a dormant default
(`LogBrkAdapter`), selected by the `integration.brk.mode` app-config key via the existing
`IntegrationMode` resolver. When the mode is unset or unknown, the Log adapter SHALL be bound so no
external call to Kadaster is ever made unknowingly.

#### Scenario: Fresh install calls nothing external

- **GIVEN** a fresh dossiq install with no `integration.brk.mode` config
- **WHEN** any flow calls `BrkAdapterInterface::lookupByKadastraleAanduiding()` or `::lookupObject()`
- **THEN** the Log adapter MUST handle it, return `LOOKUP_DEFERRED`, and no external request MUST
  leave the instance

#### Scenario: Admin flips BRK to the test tier

- **GIVEN** an admin sets `integration.brk.mode=test` with base URL and API key
- **WHEN** a lookup is made
- **THEN** the request MUST hit the configured Kadaster BRK Bevragen API v2 endpoint with the
  `X-Api-Key` header

### Requirement: Kadastrale-aanduiding lookup MUST validate its parts before any outbound call

`BrkApiAdapter::lookupByKadastraleAanduiding()` SHALL validate `sectie` against `^[A-Z]{1,2}$`
(case-insensitive input, normalized to uppercase), `perceelnummer` against `^[0-9]{1,5}$`, and an
optional `appartementsrechtVolgnummer` against `^A[0-9]{1,4}$`, before making any HTTP request,
returning `INVALID_INPUT` on a malformed value.

#### Scenario: Malformed sectie is rejected without a network call

- **GIVEN** a caller supplies sectie `"ABC"` (too long) or perceelnummer `"not-a-number"`
- **WHEN** `lookupByKadastraleAanduiding()` is called
- **THEN** the result MUST have `lookupStatus = INVALID_INPUT`
- **AND** no HTTP request MUST be made

#### Scenario: Valid kadastrale aanduiding resolves a parcel

- **GIVEN** a live adapter bound to a fixture/test endpoint
- **WHEN** `lookupByKadastraleAanduiding('VBSTD', 'A', '1234')` is called and the endpoint returns
  a matching record
- **THEN** the result MUST have `lookupStatus = FOUND` with a normalized `parcel` envelope
  (`kadastraleGemeente`, `sectie`, `perceelnummer`, `oppervlakte`, `soortCultuurBebouwd`,
  `zakelijkGerechtigden`, `geo` when present)

### Requirement: BRK zakelijkGerechtigden MUST be mapped to references only, never inline personal data

`BrkResponseMapper` SHALL map `zakelijkGerechtigdheid` entries to `identificatie` +
`aardZakelijkRecht` only. Any personal-data fields present in the raw Kadaster fragment (e.g.
`naam`, `bsn`) SHALL NOT appear in the normalized `zakelijkGerechtigden` envelope.

#### Scenario: A raw fragment carrying personal-data fields is scoped down

- **GIVEN** a raw `zakelijkGerechtigdheid` fragment containing `naam` and `bsn` fields
- **WHEN** `BrkResponseMapper::map()` normalizes it
- **THEN** the mapped `zakelijkGerechtigden` entry MUST contain only `identificatie` and
  `aardZakelijkRecht`
- **AND** MUST NOT contain a `naam` or `bsn` key

### Requirement: BRK object lookup MUST distinguish not-found from transport failure

`BrkApiAdapter::lookupObject()` SHALL map a Kadaster HTTP 404 to `NOT_FOUND` and any other
transport/HTTP failure (5xx, network error) to `LOOKUP_ERROR`, never throwing into the caller.

#### Scenario: Unknown parcel id resolves NOT_FOUND, not an exception

- **GIVEN** a live adapter and a parcel id that does not exist in the register
- **WHEN** `lookupObject('00000000000000')` is called and Kadaster returns HTTP 404
- **THEN** the result MUST have `lookupStatus = NOT_FOUND`
- **AND** no exception MUST propagate to the caller

### Requirement: The WOZ adapter MUST NOT be bound to the public WOZ-waardeloket

`WozAdapterInterface`'s live implementation SHALL target only Kadaster's Haal Centraal WOZ
Bevragen API (LV-WOZ). It SHALL NOT issue requests to `wozwaardeloket.nl`, because that service is
a web-only individual-consultation viewer with no programmatic API.

#### Scenario: No request ever targets the waardeloket

- **GIVEN** any configured tier of `WozAdapterInterface` (dormant or live)
- **WHEN** any lookup method is called
- **THEN** no HTTP request MUST be made to a `wozwaardeloket.nl` host

### Requirement: The WOZ adapter MUST be selected by configuration, defaulting to no external calls

`WozAdapterInterface` SHALL offer a live adapter (`WozApiAdapter`) alongside a dormant default
(`LogWozAdapter`), selected by the `integration.woz.mode` app-config key via `IntegrationMode`.
When the mode is unset or unknown, the Log adapter SHALL be bound.

#### Scenario: Fresh install calls nothing external

- **GIVEN** a fresh dossiq install with no `integration.woz.mode` config
- **WHEN** any flow calls a `WozAdapterInterface` lookup method
- **THEN** the Log adapter MUST handle it, return `LOOKUP_DEFERRED`, and no external request MUST
  leave the instance

### Requirement: WOZ lookup by nummeraanduiding MUST be preferred over address search when available

`WozAdapterInterface::lookupByNummeraanduiding()` SHALL exist as a distinct method from
`lookupAddress()` so a caller that already holds a BAG nummeraanduiding identificatie (e.g. from
`BagAdapterInterface::lookupAddress()`) can resolve a WOZ value without re-implementing an address
search.

#### Scenario: A caller with a nummeraanduidingId skips address search

- **GIVEN** a caller already holds a `nummeraanduidingId`
- **WHEN** `lookupByNummeraanduiding(nummeraanduidingId)` is called
- **THEN** the request MUST query `nummeraanduidingIdentificatie`, not `postcode`/`huisnummer`

### Requirement: WOZ value mapping MUST select the most recent valuation, not an arbitrary one

`WozResponseMapper` SHALL select the entry with the latest `waardepeildatum` from a WOZ object's
`vastgesteldeWaarden[]` history array as the flat `waarde`/`waardepeildatum` output fields,
regardless of the array's input order.

#### Scenario: Valuation history is sorted before selection

- **GIVEN** a raw WOZ object fragment with two `vastgesteldeWaarden[]` entries, dated 2024-01-01
  and 2025-01-01, in that (ascending) order
- **WHEN** `WozResponseMapper::map()` normalizes it
- **THEN** `waarde` and `waardepeildatum` MUST reflect the 2025-01-01 entry

### Requirement: WOZ object lookup MUST distinguish not-found from transport failure

`WozApiAdapter::lookupByWozObjectNummer()` SHALL map a Kadaster HTTP 404 to `NOT_FOUND` and any
other transport/HTTP failure to `LOOKUP_ERROR`, never throwing into the caller.

#### Scenario: Unknown wozobjectnummer resolves NOT_FOUND, not an exception

- **GIVEN** a live adapter and a wozobjectnummer that does not exist
- **WHEN** `lookupByWozObjectNummer('00000000000000')` is called and Kadaster returns HTTP 404
- **THEN** the result MUST have `lookupStatus = NOT_FOUND`
- **AND** no exception MUST propagate to the caller

### Requirement: The HTTP surface MUST expose graceful "not configured" responses, never a 500

`BrkController` and `WozController` SHALL return the adapter's `lookupStatus` (including
`LOOKUP_DEFERRED` when dormant) as a 200 JSON body rather than surfacing an HTTP error for "not
configured" — mirroring `BagController`. HTTP error statuses are reserved for request-shape
problems (400) and unauthenticated access (401).

#### Scenario: Dormant BRK adapter still returns 200

- **GIVEN** `integration.brk.mode` is unset (Log adapter bound)
- **WHEN** an authenticated user calls
  `GET /api/external/brk/parcel?kadastraleGemeenteCode=VBSTD&sectie=A&perceelnummer=1234`
- **THEN** the response MUST be HTTP 200 with `lookupStatus = LOOKUP_DEFERRED` in the body

#### Scenario: Dormant WOZ adapter still returns 200

- **GIVEN** `integration.woz.mode` is unset (Log adapter bound)
- **WHEN** an authenticated user calls `GET /api/external/woz/value?postcode=1234AB&huisnummer=10`
- **THEN** the response MUST be HTTP 200 with `lookupStatus = LOOKUP_DEFERRED` in the body

#### Scenario: Missing required query parameters is a 400

- **GIVEN** an authenticated user calls `GET /api/external/brk/parcel` with no
  `kadastraleGemeenteCode`, `sectie`, or `perceelnummer`, OR calls `GET /api/external/woz/value`
  with neither `nummeraanduidingId` nor `postcode`+`huisnummer`
- **WHEN** the request is handled
- **THEN** the response MUST be HTTP 400 with an error message identifying the missing parameters

#### Scenario: Unauthenticated access is rejected

- **GIVEN** no active user session
- **WHEN** any `brk#*` or `woz#*` route is called
- **THEN** the response MUST be HTTP 401

