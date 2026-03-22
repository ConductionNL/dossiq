---
status: implemented
---
# Prometheus Metrics Endpoint

## Purpose

Expose application metrics in Prometheus text exposition format for monitoring, alerting, and operational dashboards. Case management systems require operational visibility into case volumes, SLA compliance, processing times, and system health for both IT operations and management reporting.

**Tender demand**: 22% of tenders (15/69) require monitoring and observability capabilities. Prometheus/Grafana is the standard stack in Dutch government IT infrastructure. SLA compliance dashboards are a key requirement for contract monitoring.
**Standards**: Prometheus text exposition format, OpenMetrics, RED method (Rate, Errors, Duration)
**Feature tier**: V1 (standard metrics + health check), V2 (SLA metrics + case analytics)

## Requirements

---

### REQ-PROM-001: Metrics Endpoint

The system MUST expose a Prometheus-compatible metrics endpoint that can be scraped by Prometheus for monitoring and alerting.

**Feature tier**: V1


#### Scenario PROM-001a: Metrics endpoint returns valid Prometheus format

- GIVEN the Procest app is installed and running
- WHEN a Prometheus scraper sends `GET /index.php/apps/procest/api/metrics`
- THEN the response MUST have status code 200
- AND the response MUST have `Content-Type: text/plain; version=0.0.4; charset=utf-8`
- AND every metric MUST have a `# HELP` line, a `# TYPE` line, and one or more value lines
- AND the response body MUST be parseable by the Prometheus text format parser

#### Scenario PROM-001b: Metrics endpoint requires admin authentication

- GIVEN a non-admin user "medewerker1" authenticated via Nextcloud
- WHEN the user sends `GET /index.php/apps/procest/api/metrics`
- THEN the response MUST have status code 403 (Forbidden)
- AND no metric data MUST be exposed

#### Scenario PROM-001c: Metrics endpoint supports basic auth for scraping

- GIVEN a Prometheus scraper configured with Basic Auth credentials (admin:admin)
- WHEN the scraper sends `GET /index.php/apps/procest/api/metrics` with the `Authorization: Basic` header
- THEN the response MUST have status code 200
- AND the full metrics payload MUST be returned

#### Scenario PROM-001d: Metrics endpoint response time

- GIVEN the system has 10,000 cases and 25,000 tasks in OpenRegister
- WHEN the metrics endpoint is scraped
- THEN the response MUST be returned within 5 seconds
- AND expensive queries (case counts by status) SHOULD be cached for 30 seconds to avoid overloading OpenRegister

#### Scenario PROM-001e: Metrics scrape interval compatibility

- GIVEN a Prometheus scraper configured with a 15-second scrape interval
- WHEN the scraper sends requests every 15 seconds
- THEN the metrics endpoint MUST handle concurrent scrape requests without error
- AND counter metrics MUST be monotonically increasing (never reset between scrapes except on app restart)

---

### REQ-PROM-002: Standard Application Metrics

The system MUST expose standard application metrics following the RED method (Rate, Errors, Duration) and Prometheus naming conventions.

**Feature tier**: V1


#### Scenario PROM-002a: App info gauge

- GIVEN the Procest app version is "1.2.3" running on PHP 8.2.15 and Nextcloud 30.0.0
- WHEN the metrics endpoint is scraped
- THEN the response MUST include:
  ```
  # HELP procest_info Application information
  # TYPE procest_info gauge
  procest_info{version="1.2.3",php_version="8.2.15",nextcloud_version="30.0.0"} 1
  ```

#### Scenario PROM-002b: App up gauge reflects health status

- GIVEN the system is healthy (database reachable, dependencies available)
- WHEN the metrics endpoint is scraped
- THEN the response MUST include:
  ```
  # HELP procest_up Whether the application is healthy
  # TYPE procest_up gauge
  procest_up 1
  ```
- AND when the database is unreachable, `procest_up` MUST be `0`

#### Scenario PROM-002c: HTTP request counter

- GIVEN the system has processed 1,500 GET requests to `/api/v1/zaken` with status 200 and 23 with status 500
- WHEN the metrics endpoint is scraped
- THEN the response MUST include:
  ```
  # HELP procest_requests_total Total HTTP requests
  # TYPE procest_requests_total counter
  procest_requests_total{method="GET",endpoint="/api/v1/zaken",status="200"} 1500
  procest_requests_total{method="GET",endpoint="/api/v1/zaken",status="500"} 23
  ```

#### Scenario PROM-002d: HTTP request duration histogram

- GIVEN the system has processed requests to `/api/v1/zaken`
- WHEN the metrics endpoint is scraped
- THEN the response MUST include a histogram with buckets:
  ```
  # HELP procest_request_duration_seconds HTTP request duration in seconds
  # TYPE procest_request_duration_seconds histogram
  procest_request_duration_seconds_bucket{method="GET",endpoint="/api/v1/zaken",le="0.01"} 450
  procest_request_duration_seconds_bucket{method="GET",endpoint="/api/v1/zaken",le="0.05"} 1100
  procest_request_duration_seconds_bucket{method="GET",endpoint="/api/v1/zaken",le="0.1"} 1350
  procest_request_duration_seconds_bucket{method="GET",endpoint="/api/v1/zaken",le="0.5"} 1490
  procest_request_duration_seconds_bucket{method="GET",endpoint="/api/v1/zaken",le="1.0"} 1520
  procest_request_duration_seconds_bucket{method="GET",endpoint="/api/v1/zaken",le="+Inf"} 1523
  procest_request_duration_seconds_sum{method="GET",endpoint="/api/v1/zaken"} 87.432
  procest_request_duration_seconds_count{method="GET",endpoint="/api/v1/zaken"} 1523
  ```

#### Scenario PROM-002e: Error counter by type

- GIVEN the system has encountered 5 validation errors, 2 database errors, and 1 timeout error
- WHEN the metrics endpoint is scraped
- THEN the response MUST include:
  ```
  # HELP procest_errors_total Total errors by type
  # TYPE procest_errors_total counter
  procest_errors_total{type="validation"} 5
  procest_errors_total{type="database"} 2
  procest_errors_total{type="timeout"} 1
  ```

---

### REQ-PROM-003: Case Management Metrics

The system MUST expose case management specific metrics reflecting the current state of cases and tasks in the system.

**Feature tier**: V1


#### Scenario PROM-003a: Cases total by status and case type

- GIVEN the system contains:
  - 45 "Omgevingsvergunning" cases with status "In behandeling"
  - 12 "Omgevingsvergunning" cases with status "Afgerond"
  - 8 "Bezwaar" cases with status "Ontvangen"
- WHEN the metrics endpoint is scraped
- THEN the response MUST include:
  ```
  # HELP procest_cases_total Total cases by status and case_type
  # TYPE procest_cases_total gauge
  procest_cases_total{status="In behandeling",case_type="Omgevingsvergunning"} 45
  procest_cases_total{status="Afgerond",case_type="Omgevingsvergunning"} 12
  procest_cases_total{status="Ontvangen",case_type="Bezwaar"} 8
  ```

#### Scenario PROM-003b: Overdue cases total

- GIVEN 7 cases have `uiterlijkeEinddatumAfdoening` before today's date and are not yet closed
- WHEN the metrics endpoint is scraped
- THEN the response MUST include:
  ```
  # HELP procest_cases_overdue_total Cases past their deadline
  # TYPE procest_cases_overdue_total gauge
  procest_cases_overdue_total 7
  ```

#### Scenario PROM-003c: Tasks total by status

- GIVEN the system contains 30 tasks with status "open", 15 with "in_progress", and 85 with "completed"
- WHEN the metrics endpoint is scraped
- THEN the response MUST include:
  ```
  # HELP procest_tasks_total Total tasks by status
  # TYPE procest_tasks_total gauge
  procest_tasks_total{status="open"} 30
  procest_tasks_total{status="in_progress"} 15
  procest_tasks_total{status="completed"} 85
  ```

#### Scenario PROM-003d: Overdue tasks total

- GIVEN 4 tasks have `deadline` before today's date and status is not "completed"
- WHEN the metrics endpoint is scraped
- THEN the response MUST include:
  ```
  # HELP procest_tasks_overdue_total Tasks past their deadline
  # TYPE procest_tasks_overdue_total gauge
  procest_tasks_overdue_total 4
  ```

#### Scenario PROM-003e: Cases created today counter

- GIVEN 12 new cases were created today
- WHEN the metrics endpoint is scraped
- THEN the response MUST include:
  ```
  # HELP procest_cases_created_today Cases created today
  # TYPE procest_cases_created_today gauge
  procest_cases_created_today 12
  ```

---

### REQ-PROM-004: Health Check Endpoint

The system MUST expose a health check endpoint for container orchestration (Kubernetes liveness/readiness probes) and monitoring dashboards.

**Feature tier**: V1


#### Scenario PROM-004a: Healthy system response

- GIVEN the database is reachable and filesystem is writable
- WHEN a probe sends `GET /index.php/apps/procest/api/health`
- THEN the response MUST have status code 200
- AND the response body MUST be:
  ```json
  {
    "status": "ok",
    "version": "1.2.3",
    "checks": {
      "database": "ok",
      "filesystem": "ok"
    }
  }
  ```

#### Scenario PROM-004b: Degraded system response

- GIVEN the database is reachable but the filesystem temp directory is not writable
- WHEN a probe sends `GET /index.php/apps/procest/api/health`
- THEN the response MUST have status code 503 (Service Unavailable)
- AND the response body MUST be:
  ```json
  {
    "status": "degraded",
    "version": "1.2.3",
    "checks": {
      "database": "ok",
      "filesystem": "failed: cannot write to temp directory"
    }
  }
  ```

#### Scenario PROM-004c: Error system response

- GIVEN the database is unreachable
- WHEN a probe sends `GET /index.php/apps/procest/api/health`
- THEN the response MUST have status code 503
- AND `status` MUST be "error"
- AND `checks.database` MUST contain the error message

#### Scenario PROM-004d: OpenRegister dependency check

- GIVEN the OpenRegister app is disabled or not installed
- WHEN a probe sends `GET /index.php/apps/procest/api/health`
- THEN `checks` MUST include `"openregister": "failed: app not enabled"`
- AND the overall status MUST be "error" (OpenRegister is a hard dependency)

#### Scenario PROM-004e: Health check response time

- GIVEN the system is under normal load
- WHEN a health check probe is sent
- THEN the response MUST be returned within 2 seconds
- AND the health check MUST NOT perform expensive database queries (simple connectivity test only)

---

### REQ-PROM-005: ZGW API Metrics

The system MUST expose metrics for ZGW API operations, enabling monitoring of the ZGW compliance layer that wraps OpenRegister.

**Feature tier**: V1


#### Scenario PROM-005a: ZGW API request counter

- GIVEN the system has processed ZGW API requests across all 4 controllers
- WHEN the metrics endpoint is scraped
- THEN the response MUST include:
  ```
  # HELP procest_zgw_requests_total ZGW API requests
  # TYPE procest_zgw_requests_total counter
  procest_zgw_requests_total{api="zrc",method="POST",status="201"} 150
  procest_zgw_requests_total{api="zrc",method="GET",status="200"} 2340
  procest_zgw_requests_total{api="ztc",method="GET",status="200"} 890
  procest_zgw_requests_total{api="drc",method="POST",status="201"} 67
  procest_zgw_requests_total{api="brc",method="POST",status="201"} 23
  ```

#### Scenario PROM-005b: ZGW API latency histogram

- GIVEN the system has processed ZGW API requests
- WHEN the metrics endpoint is scraped
- THEN the response MUST include histogram metrics for each API:
  ```
  # HELP procest_zgw_request_duration_seconds ZGW API request duration
  # TYPE procest_zgw_request_duration_seconds histogram
  ```
- AND buckets MUST be: 0.01, 0.05, 0.1, 0.25, 0.5, 1.0, 2.5, 5.0, +Inf

#### Scenario PROM-005c: ZGW validation error counter

- GIVEN 15 ZGW API requests were rejected by `ZgwZrcRulesService` validation
- WHEN the metrics endpoint is scraped
- THEN the response MUST include:
  ```
  # HELP procest_zgw_validation_errors_total ZGW validation errors by rule
  # TYPE procest_zgw_validation_errors_total counter
  procest_zgw_validation_errors_total{api="zrc",rule="missing_required_field"} 8
  procest_zgw_validation_errors_total{api="zrc",rule="invalid_status_transition"} 5
  procest_zgw_validation_errors_total{api="zrc",rule="case_closed"} 2
  ```

---

### REQ-PROM-006: SLA Compliance Metrics

The system MUST expose SLA compliance metrics for management dashboards and tender contract monitoring.

**Feature tier**: V2


#### Scenario PROM-006a: SLA compliance percentage by case type

- GIVEN case type "Omgevingsvergunning" has a 56-day SLA (uiterlijkeEinddatumAfdoening)
- AND 40 of 45 active cases are within SLA, 5 are overdue
- WHEN the metrics endpoint is scraped
- THEN the response MUST include:
  ```
  # HELP procest_sla_compliance_ratio SLA compliance ratio by case_type (0.0-1.0)
  # TYPE procest_sla_compliance_ratio gauge
  procest_sla_compliance_ratio{case_type="Omgevingsvergunning"} 0.889
  ```

#### Scenario PROM-006b: Average case processing time

- GIVEN 50 "Omgevingsvergunning" cases have been closed in the last 30 days
- AND the average time from startDate to endDate is 32.5 days
- WHEN the metrics endpoint is scraped
- THEN the response MUST include:
  ```
  # HELP procest_case_processing_days Average case processing time in days
  # TYPE procest_case_processing_days gauge
  procest_case_processing_days{case_type="Omgevingsvergunning"} 32.5
  ```

#### Scenario PROM-006c: Cases approaching deadline

- GIVEN 3 cases have their `uiterlijkeEinddatumAfdoening` within the next 7 days and are not yet closed
- WHEN the metrics endpoint is scraped
- THEN the response MUST include:
  ```
  # HELP procest_cases_approaching_deadline Cases with deadline within 7 days
  # TYPE procest_cases_approaching_deadline gauge
  procest_cases_approaching_deadline 3
  ```

#### Scenario PROM-006d: Case volume trend (created vs closed)

- GIVEN 12 cases were created today and 8 cases were closed today
- WHEN the metrics endpoint is scraped
- THEN the response MUST include:
  ```
  # HELP procest_cases_created_total Total cases created (cumulative counter)
  # TYPE procest_cases_created_total counter
  procest_cases_created_total 1234
  # HELP procest_cases_closed_total Total cases closed (cumulative counter)
  # TYPE procest_cases_closed_total counter
  procest_cases_closed_total 1180
  ```

---

### REQ-PROM-007: Metric Collection Infrastructure

The system MUST provide infrastructure for collecting request-level metrics (counters, histograms) throughout the application lifecycle.

**Feature tier**: V1


#### Scenario PROM-007a: Middleware-based request instrumentation

- GIVEN the `ZgwAuthMiddleware` processes every ZGW API request
- WHEN a request passes through the middleware
- THEN the middleware MUST record: start time, HTTP method, endpoint path, and response status code
- AND on response completion, it MUST calculate the duration and increment the appropriate counter/histogram
- AND metrics MUST be stored in APCu for cross-request persistence

#### Scenario PROM-007b: Metrics survive Apache graceful restart

- GIVEN metrics have been collected over the past hour
- WHEN an Apache graceful restart occurs (e.g., `docker exec nextcloud apache2ctl graceful`)
- THEN counter metrics MUST be preserved via APCu (persists across graceful restarts)
- AND histogram bucket counts MUST be preserved
- AND if APCu is cleared, counters MUST restart from 0 (Prometheus handles counter resets)

#### Scenario PROM-007c: Metric label cardinality control

- GIVEN the system tracks request metrics with labels (method, endpoint, status)
- WHEN a request has a unique URL path (e.g., `/api/v1/zaken/{uuid}`)
- THEN the endpoint label MUST be normalized to the route pattern: `/api/v1/zaken/{id}`
- AND individual UUIDs MUST NOT appear as label values (prevents label explosion)
- AND the total number of unique label combinations MUST be <=500

---

### REQ-PROM-008: Grafana Dashboard Compatibility

The system SHALL provide a Grafana dashboard definition (JSON) that visualizes the exposed metrics for immediate deployment in municipal monitoring infrastructure.

**Feature tier**: V2


#### Scenario PROM-008a: Dashboard JSON export

- GIVEN all Prometheus metrics are exposed
- WHEN an admin exports the Grafana dashboard definition
- THEN a JSON file MUST be provided in `procest/docs/monitoring/grafana-dashboard.json`
- AND the dashboard MUST include panels for: case volume over time, SLA compliance gauge, overdue cases alert, ZGW API latency, error rate, system health

#### Scenario PROM-008b: Alert rules definition

- GIVEN Prometheus Alertmanager is configured
- WHEN alert rules are loaded
- THEN a rules file MUST be provided in `procest/docs/monitoring/prometheus-alerts.yml`
- AND the rules MUST include alerts for:
  - `ProcestDown` (procest_up == 0 for >1 minute)
  - `ProcestHighErrorRate` (error rate >5% for >5 minutes)
  - `ProcestSlaBreachRisk` (cases_approaching_deadline > 10)
  - `ProcestHighLatency` (p95 request duration >2s for >5 minutes)

#### Scenario PROM-008c: Dashboard supports multi-instance

- GIVEN a municipality running 3 Procest instances (production, staging, test)
- WHEN the Grafana dashboard is loaded
- THEN the dashboard MUST support an `instance` variable selector
- AND all panels MUST filter by the selected instance
- AND the SLA compliance panel MUST support comparison across instances

---

### REQ-PROM-009: Metric Caching and Performance

The system MUST ensure that metric collection does not degrade application performance and that expensive queries are cached.

**Feature tier**: V1


#### Scenario PROM-009a: Case count query caching

- GIVEN the `getCaseCounts()` method queries OpenRegister objects with JSON extraction
- WHEN the metrics endpoint is scraped twice within 30 seconds
- THEN the second scrape MUST use cached results from APCu
- AND the cache key MUST be `procest_metrics_case_counts`
- AND the cache TTL MUST be 30 seconds

#### Scenario PROM-009b: Overdue case count caching

- GIVEN the `getOverdueCasesCount()` method compares dates on all open cases
- WHEN the metrics endpoint is scraped
- THEN the result MUST be cached for 60 seconds (overdue counts change less frequently)
- AND the cache MUST be invalidated when a case status changes to "Afgerond"

#### Scenario PROM-009c: Request counter zero-overhead recording

- GIVEN the request counter is incremented on every request
- WHEN a request is processed
- THEN the counter increment MUST use APCu `apcu_inc()` (atomic, no locks)
- AND the overhead MUST be <1ms per request
- AND if APCu is unavailable, counter recording MUST be silently skipped (no errors)

---

### REQ-PROM-010: StUF Protocol Metrics

When StUF support is implemented (see `../stuf-support/spec.md`), the system MUST expose StUF-specific metrics for monitoring legacy integration health.

**Feature tier**: V2


#### Scenario PROM-010a: StUF message counter

- GIVEN the system has processed inbound and outbound StUF messages
- WHEN the metrics endpoint is scraped
- THEN the response MUST include:
  ```
  # HELP procest_stuf_messages_total StUF messages processed
  # TYPE procest_stuf_messages_total counter
  procest_stuf_messages_total{direction="inbound",type="zakLk01",result="success"} 45
  procest_stuf_messages_total{direction="inbound",type="zakLv01",result="success"} 120
  procest_stuf_messages_total{direction="outbound",type="npsLv01",result="success"} 89
  procest_stuf_messages_total{direction="outbound",type="npsLv01",result="fault"} 3
  ```

#### Scenario PROM-010b: StUF latency histogram

- GIVEN outbound StUF calls to BRP typically take 200-500ms
- WHEN the metrics endpoint is scraped
- THEN the response MUST include a histogram for StUF call duration:
  ```
  # HELP procest_stuf_duration_seconds StUF message processing duration
  # TYPE procest_stuf_duration_seconds histogram
  ```
- AND buckets MUST be: 0.1, 0.25, 0.5, 1.0, 2.5, 5.0, 10.0, +Inf

#### Scenario PROM-010c: StUF endpoint availability

- GIVEN 3 StUF endpoints are configured (BRP, legacy zaaksysteem, DMS)
- WHEN the health check runs
- THEN the response MUST include StUF endpoint status:
  ```
  # HELP procest_stuf_endpoint_up StUF endpoint availability
  # TYPE procest_stuf_endpoint_up gauge
  procest_stuf_endpoint_up{endpoint="brp",type="stuf-bg"} 1
  procest_stuf_endpoint_up{endpoint="legacy-zaak",type="stuf-zkn"} 1
  procest_stuf_endpoint_up{endpoint="dms",type="stuf-zkn"} 0
  ```

---

## Dependencies

- **OpenRegister**: Case and task data queried via direct database queries (JSON extraction from `openregister_objects` table).
- **APCu**: PHP in-memory cache for request counters, histogram buckets, and query result caching.
- **Nextcloud IAppManager**: For retrieving app version information.
- **Nextcloud IDBConnection**: For database connectivity check and data queries.
- **ZgwAuthMiddleware**: For intercepting requests and recording metrics.
- **Prometheus**: External monitoring system that scrapes the metrics endpoint.
- **Grafana** (optional): Visualization dashboard for the exposed metrics.

## Current Implementation Status

**Partially implemented.** `MetricsController` and `HealthController` exist with basic functionality:

- `MetricsController.index()` returns Prometheus-formatted text with: `procest_info`, `procest_up`, `procest_cases_total` (by status and case_type), `procest_cases_overdue_total`, `procest_tasks_total` (by status), `procest_tasks_overdue_total`.
- `HealthController.index()` returns JSON with database and filesystem checks.
- **NOT implemented**: Request counters (`procest_requests_total`), request duration histograms, error counters, ZGW-specific metrics, SLA compliance metrics, StUF metrics, caching, middleware instrumentation, Grafana dashboard, alert rules, OpenRegister dependency check.

## Standards & References

- **Prometheus text exposition format**: https://prometheus.io/docs/instrumenting/exposition_formats/ -- The wire format for metrics scraping.
- **OpenMetrics specification**: https://openmetrics.io/ -- Successor to Prometheus format, adds metadata and exemplars.
- **RED method**: Rate, Errors, Duration -- standard approach for service monitoring.
- **USE method**: Utilization, Saturation, Errors -- for infrastructure monitoring.
- **Grafana dashboards**: https://grafana.com/docs/grafana/latest/dashboards/ -- JSON model for dashboard definitions.
- **Prometheus Alertmanager**: https://prometheus.io/docs/alerting/latest/alertmanager/ -- Alert rules and notification routing.
- **OpenRegister MetricsService**: Reference implementation in the OpenRegister app.
- **Nextcloud server monitoring**: Nextcloud's built-in monitoring endpoints at `/ocs/v2.php/apps/serverinfo/api/v1/info`.
- **ISO 27001 A.12.4**: Logging and monitoring requirements for information security.
- **BIO (Baseline Informatiebeveiliging Overheid)**: Dutch government security baseline requiring logging and monitoring.

## Specificity Assessment

The spec defines 10 requirements with 3-5 scenarios each, covering the full monitoring lifecycle from basic metrics through SLA dashboards. The existing `MetricsController` and `HealthController` provide a foundation.

**Competitive context**: Dimpact ZAC uses OpenTelemetry with Prometheus, Grafana, and Tempo for observability. Flowable exposes JMX and REST metrics. Procest's native Prometheus endpoint provides equivalent monitoring capability without requiring a separate APM agent.
