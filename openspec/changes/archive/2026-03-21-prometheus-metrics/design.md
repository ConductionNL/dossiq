# Design: Prometheus Metrics

## Architecture
- **Backend**: `MetricsController.php` exposing `/api/metrics` endpoint
- **Format**: Prometheus text exposition format
- **Metrics**: Case volumes, SLA compliance, processing times, system health
- **Auth**: Nextcloud session auth (admin only)

## Components
| Component | Path | Purpose |
|-----------|------|---------|
| `MetricsController.php` | `lib/Controller/MetricsController.php` | Prometheus metrics endpoint |
| `HealthController.php` | `lib/Controller/HealthController.php` | Health check endpoint |
