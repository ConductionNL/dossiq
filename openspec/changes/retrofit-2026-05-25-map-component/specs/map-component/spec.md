---
retrofit_extensions:
  - REQ-005
  - REQ-006
---

# Map Component — backend GIS proxy (retrofit)

## Requirements

### REQ-005: GisProxyController SHALL expose `proxy` and `capabilities` action endpoints for external GIS services

`OCA\Procest\Controller\GisProxyController::proxy()` SHALL accept `url`, `query`, and `type` parameters and forward the request to `GisProxyService::proxyRequest()`. `GisProxyController::capabilities()` SHALL accept `url` and `type` parameters and delegate to `GisProxyService::getCapabilities()`. Both endpoints SHALL be authenticated (`#[NoAdminRequired]`) and SHALL return JSON error envelopes — HTTP 400 when the required `url` parameter is missing, HTTP 403 when the upstream allowlist rejects the URL, HTTP 429 when rate-limited, and HTTP 502 for any other upstream failure.

#### Scenario: Missing url returns 400
- **WHEN** a caller invokes `proxy()` or `capabilities()` without a `url` parameter
- **THEN** the controller SHALL return a JSON response with status 400 and body `{"error": "Missing required parameter: url"}`

#### Scenario: Disallowed URL maps to 403
- **GIVEN** a `url` whose host is not in the configured `wmsLayer` allowlist
- **WHEN** `proxy()` is invoked
- **THEN** the service raises `\RuntimeException` with code 403
- **AND** the controller SHALL return a JSON response with status 403 and body `{"error": "URL not allowed: <message>"}`

#### Scenario: Rate-limited upstream maps to 429
- **GIVEN** the current user has already issued 100 proxied requests in the current minute
- **WHEN** `proxy()` is invoked
- **THEN** the controller SHALL return a JSON response with status 429 and body `{"error": "Rate limit exceeded"}`

#### Scenario: Generic upstream failure maps to 502
- **GIVEN** the proxied request fails for any reason other than 403/429
- **WHEN** `proxy()` is invoked
- **THEN** the controller SHALL return a JSON response with status 502 and body `{"error": "Proxy request failed: <message>"}`

### REQ-006: GisProxyService SHALL enforce a host-based allowlist, per-user rate limiting, and 5-minute response caching

`OCA\Procest\Service\GisProxyService` SHALL provide `proxyRequest()`, `getCapabilities()`, host-based allowlist validation (`isUrlAllowed()`), and per-user-per-minute rate limiting (`checkRateLimit()`). The allowlist SHALL unconditionally accept hosts containing `pdok.nl` or `kadaster.nl`, and otherwise SHALL compare the request host to the parsed host of every configured `wmsLayer` object in the configured register/schema. Successful responses SHALL be cached in the distributed cache `procest_gis_proxy` keyed by `md5(fullUrl)` for 300 seconds. The per-user rate limit SHALL allow at most 100 requests per minute (key: `rate_limit_<uid>_<YmdHi>`); exceeding the limit SHALL raise `\RuntimeException` with code 429.

#### Scenario: PDOK and Kadaster hosts are always allowed
- **GIVEN** a request URL with host `service.pdok.nl` or any subdomain of `kadaster.nl`
- **WHEN** `isUrlAllowed()` is evaluated
- **THEN** the method SHALL return `true` without consulting OpenRegister

#### Scenario: Non-PDOK host requires a matching wmsLayer record
- **GIVEN** a request URL with host `example.org`
- **AND** no `wmsLayer` record exists whose `url` parses to that host
- **WHEN** `proxyRequest()` is invoked
- **THEN** the service SHALL throw `\RuntimeException('URL not in configured layer allowlist', 403)`

#### Scenario: Cached response is returned without re-fetching
- **GIVEN** `proxyRequest($url, $query, $type)` was called once and the response cached
- **WHEN** the same `(url, query)` pair is requested again within 300 seconds
- **THEN** the service SHALL return the cached array without issuing a new HTTP request

#### Scenario: Rate-limit overflow raises 429
- **GIVEN** the current user's counter `rate_limit_<uid>_<YmdHi>` is already at 100
- **WHEN** `proxyRequest()` is invoked
- **THEN** the service SHALL throw `\RuntimeException('Rate limit exceeded', 429)`
- **AND** SHALL log a `warning` event with the userId and current count

#### Notes
- The HTTP forwarder uses `file_get_contents()` with a stream context — a TODO for future hardening is to migrate to Guzzle via `IClient` so timeouts/headers/redirects follow the rest of Procest.
- XML responses are converted to associative arrays via `simplexml_load_string` + JSON round-trip; behavior on malformed XML is "return raw string", which is observed but undocumented.
