# Proposal: prometheus-metrics

## Summary

Enhance the existing Prometheus metrics and health check endpoints to align with the spec requirements: add Nextcloud version to info gauge, add OpenRegister dependency check to health endpoint, add `procest_cases_created_today` metric, and add APCu-based caching for expensive database queries. Focuses on V1 tier requirements that build on the already-implemented MetricsController and HealthController.

## Motivation

The MetricsController and HealthController already exist with basic functionality. The spec (REQ-PROM-001 through REQ-PROM-009) identifies gaps: the info gauge lacks Nextcloud version, the health check doesn't verify OpenRegister availability, there's no caching for expensive queries, and no `cases_created_today` metric. These are straightforward enhancements that improve monitoring fidelity without architectural changes.

## Affected Projects

- [x] Project: `procest` — Enhance MetricsController and HealthController

## Scope

### In Scope (V1)

- **REQ-PROM-002a**: Add `nextcloud_version` label to `procest_info` gauge
- **REQ-PROM-003e**: Add `procest_cases_created_today` gauge metric
- **REQ-PROM-004d**: Add OpenRegister dependency check to HealthController
- **REQ-PROM-009a/b**: Add APCu caching for case count and overdue queries
- Unit tests for MetricsController and HealthController

### Out of Scope (V2 / future)

- Request counters and histograms (REQ-PROM-002c/d) — requires middleware instrumentation
- ZGW-specific metrics (REQ-PROM-005) — requires middleware changes
- SLA compliance metrics (REQ-PROM-006) — V2 feature tier
- StUF metrics (REQ-PROM-010) — V2 feature tier
- Grafana dashboard JSON (REQ-PROM-008) — V2 feature tier
- Error counters (REQ-PROM-002e) — requires middleware changes

## Approach

1. Enhance `MetricsController::collectMetrics()` to include Nextcloud version in the info gauge and add a `procest_cases_created_today` metric
2. Add APCu caching wrapper for `getCaseCounts()`, `getOverdueCasesCount()`, `getTaskCounts()`, `getOverdueTasksCount()`
3. Add OpenRegister availability check to `HealthController::index()`
4. Write unit tests for both controllers
