---
retrofit_extensions:
  - REQ-PDOK-04
  - REQ-PDOK-05
---

# PDOK Integration — backend ingress services (retrofit)

## Requirements

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

### REQ-PDOK-05: PdokLocatieserverService SHALL expose suggest/free/lookup/reverse/health for PDOK address search

`OCA\Procest\Service\Pdok\PdokLocatieserverService` SHALL expose five public methods backing the Locatieserver API:
- `suggest(string $query, array $fq = [], int $rows = 10)` — type-ahead suggestions
- `free(string $query, array $fq = [])` — free-text address search
- `lookup(string $id)` — full record lookup by Locatieserver identifier
- `reverse(float $lat, float $lng)` — reverse geocoding from WGS84
- `health()` — endpoint health probe returning a status string

The service SHALL respect optional OpenConnector routing (analogous to `PdokBagService` — IAppConfig source key when non-empty) and SHALL cache results per `(method, query/id/coords, fq, rows)` tuple.

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

#### Notes
- The five methods deliberately mirror Locatieserver's documented endpoints — call signatures are not Procest-domain shapes.
- Cache TTL: 24h for `lookup`/`reverse`, 5 min for `suggest` (rationale: address autocomplete invalidates faster than full-record lookups).
- Outage handling: 3 consecutive 5xx responses within 60 s flip the service into a 5 min "degraded" state during which `suggest()` short-circuits to an empty array (so `LocationService` can fall back to free-text per REQ-CL-3 graceful degradation).
