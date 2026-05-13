# Tasks: prometheus-metrics

## Implementation Tasks

- [x] **T01**: Add Nextcloud version label to `procest_info` gauge in MetricsController
- [x] **T02**: Add `procest_cases_created_today` gauge metric to MetricsController
- [x] **T03**: Add APCu caching for expensive metric queries (30s TTL)
- [x] **T04**: Add OpenRegister dependency check to HealthController
- [x] **T05**: Write unit tests for MetricsController
- [x] **T06**: Write unit tests for HealthController

## Verification Tasks

- [x] **V01**: `procest_info` gauge includes `nextcloud_version` label
- [x] **V02**: `procest_cases_created_today` metric is present in output
- [x] **V03**: APCu caching works (second call within 30s returns cached data)
- [x] **V04**: Health check includes `openregister` check
- [x] **V05**: Health status is "error" when OpenRegister is unavailable
- [x] **V06**: All unit tests pass
