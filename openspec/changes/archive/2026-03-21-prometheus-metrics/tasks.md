# Tasks: Prometheus Metrics

## Task 1: Metrics endpoint [MVP] [DONE]
- **spec_ref**: prometheus-metrics/spec.md
- **files**: `lib/Controller/MetricsController.php`
- **acceptance**: `/api/metrics` returns Prometheus-formatted metrics

## Task 2: Health check endpoint [MVP] [DONE]
- **spec_ref**: prometheus-metrics/spec.md
- **files**: `lib/Controller/HealthController.php`
- **acceptance**: `/api/health` returns system status

## Task 3: Unit tests (ADR-009) [DONE]
- **spec_ref**: ADR-009
- **acceptance**: Metrics endpoint tests pass

## Task 4: Documentation (ADR-010) [DONE]
- **spec_ref**: ADR-010
- **acceptance**: Metrics endpoint documented

## Task 5: i18n support (ADR-005) [DONE]
- **spec_ref**: ADR-005
- **acceptance**: Metric labels in English
