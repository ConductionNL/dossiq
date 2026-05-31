---
retrofit_extensions:
  - REQ-PROM-011
---

# Prometheus Metrics — controller contract (retrofit)

## Requirements

### REQ-PROM-011: MetricsController::index SHALL be the single Prometheus exposition surface and SHALL honor the observed cache + header contract

`OCA\Procest\Controller\MetricsController` SHALL expose a single action method `index()` that returns a `TextPlainResponse` containing the full metric set defined in REQ-PROM-001..010. The controller SHALL:
- Be annotated `#[NoCSRFRequired]` so Prometheus scrape jobs can poll without a CSRF token.
- Emit `Content-Type: text/plain; version=0.0.4; charset=utf-8` exactly (the Prometheus 0.0.4 text exposition version is part of the wire contract — scrapers reject anything else).
- Cache individual metric queries with `CACHE_TTL_DEFAULT = 30` seconds for most series and `CACHE_TTL_OVERDUE = 60` seconds for the overdue-case series (which change less often and are expensive to compute).
- Delegate metric collection to a private `collectMetrics(): string` helper that concatenates app-info / case-management / ZGW / SLA / StUF sections into the canonical exposition format.

#### Scenario: Response Content-Type carries the 0.0.4 version
- **WHEN** a Prometheus scraper hits `/apps/procest/metrics`
- **THEN** the response SHALL have status 200
- **AND** the `Content-Type` header SHALL be `text/plain; version=0.0.4; charset=utf-8`

#### Scenario: Endpoint is reachable without CSRF token
- **GIVEN** a Prometheus scrape job with no CSRF token
- **WHEN** it requests `/apps/procest/metrics`
- **THEN** the request SHALL succeed (Nextcloud CSRF middleware is bypassed via `#[NoCSRFRequired]`)

#### Scenario: Overdue-case metric uses longer cache TTL
- **GIVEN** the overdue-case metric was computed and cached at t=0
- **WHEN** `/metrics` is hit again at t=45
- **THEN** the cached value SHALL still be served (TTL = 60s, not expired)

#### Notes
- This REQ does NOT replace REQ-PROM-001..010 — those define the metric set; this REQ defines the controller-level transport contract that exposes them.
- The `CACHE_TTL_*` constants live as `private const` on the controller; if a future REQ requires longer/shorter TTLs, both this REQ and the constants must move together.
