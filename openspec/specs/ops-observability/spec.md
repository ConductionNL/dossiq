---
status: done
retrofit: true
---

# Ops Observability Specification

## Purpose

@e2e exclude Pure health-check endpoint covered by integration tests; no Playwright UI surface.

Expose a single health-check endpoint that container orchestrators (k8s, docker-compose `healthcheck`) and external monitoring (Prometheus blackbox-exporter, uptime checks) can probe to determine whether procest is serving correctly. The endpoint reports an aggregate `status`, the running `version`, and per-component sub-checks (database, OpenRegister hard-dep, filesystem) so that a failing probe gives operators enough context to triage without needing a separate logs query.

## Requirements

### REQ-001: Aggregate health-check JSON endpoint with status / version / checks shape

The system SHALL expose `HealthController::index` as a `@NoCSRFRequired` JSON endpoint that returns `{status: 'ok' | 'degraded' | 'error', version: <appVersion>, checks: {database: ..., openregister: ..., filesystem: ...}}`.

#### Scenario: Shape stability

- WHEN any probe calls the health endpoint
- THEN the response SHALL always include the three keys `status`, `version`, `checks` (even when individual sub-checks fail) so monitoring parsers can rely on the schema

#### Scenario: Version reporting

- WHEN the endpoint returns
- THEN `version` SHALL be `IAppManager::getAppVersion(APP_ID)` or literal `'unknown'` when the lookup throws

#### Notes

- `@NoCSRFRequired` is required so external probes don't need a Nextcloud session.

### REQ-002: Three sub-checks (database, OpenRegister hard-dep, filesystem) with severity tiering

The system SHALL run three sub-checks on every probe — `database` (executes `SELECT 1`), `openregister` (verifies the app is enabled — hard dependency), and `filesystem` (writes + deletes a temp file at `sys_get_temp_dir()/procest_health_<pid>`). Each sub-check returns `'ok'` or `'failed: <reason>'`. Severity tiers: database OR openregister fail → aggregate `status='error'`; only filesystem fails → aggregate `status='degraded'`.

#### Scenario: Database check

- WHEN the database sub-check runs
- THEN it SHALL execute `SELECT 1` via the Nextcloud query builder, close the cursor, and return `'ok'` on success, `'failed: <message>'` on `\Exception` (also logged via `LoggerInterface::error`)

#### Scenario: OpenRegister hard-dep

- WHEN the OpenRegister sub-check runs
- THEN it SHALL call `IAppManager::isEnabledForUser('openregister')` and return `'ok'` when enabled, `'failed: app not enabled'` when disabled, or `'failed: <message>'` on exception

#### Scenario: Filesystem check

- WHEN the filesystem sub-check runs
- THEN it SHALL write `'health'` to `sys_get_temp_dir() . '/procest_health_' . getmypid()`, unlink it, and return `'ok'` on success or `'failed: <reason>'` on failure

#### Scenario: Severity tiering

- WHEN computing aggregate `status`
- THEN database OR openregister failure SHALL set `status='error'`
- AND only filesystem failure (with DB + OR both `'ok'`) SHALL set `status='degraded'`
- AND all three `'ok'` SHALL set `status='ok'`

#### Notes

- The filesystem check uses the system temp dir, not the Nextcloud data dir — a degraded result here means PHP can't even reach `/tmp`. A real "data dir writable" check is observed-but-stubbed.

### REQ-003: HTTP status reflects aggregate health

The system SHALL return HTTP `200 OK` when `status === 'ok'` and HTTP `503 Service Unavailable` for both `'degraded'` and `'error'` — so a probe that only inspects status code (typical for k8s readiness probes) still routes traffic correctly.

#### Scenario: 503 covers both degraded and error

- WHEN aggregate `status` is `'degraded'` OR `'error'`
- THEN the HTTP status SHALL be `503` (not `200`) — the body's `status` field carries the gradation

#### Notes

- This is intentional: k8s readiness probes are binary; the `'degraded'` JSON tier exists for humans + Prometheus alerting rules but a degraded probe still pulls the pod out of rotation. Future work may split into liveness (200/503) and readiness (200/503) endpoints if finer control is needed.
