# Retrofit — prometheus-metrics

Adds 1 new REQ to the `prometheus-metrics` capability that ties the existing `REQ-PROM-001..010` requirement set to its single implementation surface — the `MetricsController::index()` text-exposition endpoint — and codifies the observed controller-level contract (cache TTLs, Content-Type header version, NoCSRFRequired posture).

The existing REQ-PROM-001 spec mandates "MUST provide a /metrics endpoint", but does not lock down the cache TTLs (`CACHE_TTL_DEFAULT=30`, `CACHE_TTL_OVERDUE=60`) or the Prometheus exposition version (`0.0.4`) actually emitted by the controller. This retrofit captures those values as observed-behavior contracts.

## Affected code units
- lib/Controller/MetricsController.php — `index()` action endpoint + internal `collectMetrics()` helper (1 public method, 1 cluster covering all 10 REQ-PROM-NN requirements)

Source: openspec/coverage-report.md generated 2026-05-24. Tracks ConductionNL/procest#565.
