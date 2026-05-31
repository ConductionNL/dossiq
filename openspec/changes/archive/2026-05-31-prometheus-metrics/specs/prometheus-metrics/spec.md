# Delta: prometheus-metrics

## Changes from base spec

### REQ-PROM-002a (ENHANCED)
- Added `nextcloud_version` label to `procest_info` gauge

### REQ-PROM-002b (ENHANCED)
- `procest_up` gauge now reflects actual database health (was hardcoded to 1)

### REQ-PROM-003e (IMPLEMENTED)
- Added `procest_cases_created_today` gauge metric

### REQ-PROM-004d (IMPLEMENTED)
- Added OpenRegister dependency check to health endpoint
- OpenRegister unavailable sets overall status to "error"

### REQ-PROM-009a/b (IMPLEMENTED)
- Added APCu caching for all expensive metric queries
- Case counts and task counts cached for 30 seconds
- Overdue counts cached for 60 seconds
- Graceful fallback when APCu is unavailable
