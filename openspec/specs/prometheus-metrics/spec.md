# Prometheus Metrics Endpoint

## Purpose
Expose application metrics in Prometheus text exposition format at `GET /api/metrics` for monitoring, alerting, and operational dashboards.

## Requirements

### REQ-PROM-001: Metrics Endpoint
- MUST expose `GET /index.php/apps/procest/api/metrics` returning `text/plain; version=0.0.4; charset=utf-8`
- MUST require admin authentication (Nextcloud admin or API token)
- MUST return metrics in Prometheus text exposition format

### REQ-PROM-002: Standard Metrics
Every app MUST expose these standard metrics:
- `procest_info` (gauge, labels: version, php_version, nextcloud_version) — always 1
- `procest_up` (gauge) — 1 if app is healthy, 0 if degraded
- `procest_requests_total` (counter, labels: method, endpoint, status) — HTTP request count
- `procest_request_duration_seconds` (histogram, labels: method, endpoint) — request latency
- `procest_errors_total` (counter, labels: type) — error count by type

### REQ-PROM-003: App-Specific Metrics
- `procest_cases_total` (gauge, labels: status, case_type) — total cases
- `procest_cases_overdue_total` (gauge) — cases past deadline
- `procest_tasks_total` (gauge, labels: status) — total tasks
- `procest_tasks_overdue_total` (gauge) — tasks past deadline
- `procest_zgw_requests_total` (counter, labels: api, method, status) — ZGW API calls
- `procest_zgw_request_duration_seconds` (histogram, labels: api) — ZGW API latency

### REQ-PROM-004: Health Check
- MUST expose `GET /index.php/apps/procest/api/health` returning JSON `{"status": "ok"|"degraded"|"error", "checks": {...}}`
- Checks: database connectivity, required dependencies available, ZGW API reachability

## Current Implementation Status
- **Not implemented**: No MetricsController, HealthController, or metrics/monitoring code exists in the app.

## Standards & References
- Prometheus text exposition format: https://prometheus.io/docs/instrumenting/exposition_formats/
- OpenMetrics specification: https://openmetrics.io/
- Nextcloud server monitoring patterns
- OpenRegister MetricsService and HeartbeatController as reference implementation

## Specificity Assessment
Highly specific — metric names, types, and labels are fully defined. Implementation follows a standard pattern that can be shared via a base MetricsService trait/class from OpenRegister.
