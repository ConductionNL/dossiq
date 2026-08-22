# Prometheus Metrics

The Prometheus metrics feature exposes operational metrics for monitoring Dossiq in production environments.

## Overview

For enterprise deployments, Dossiq can expose metrics in Prometheus format, enabling integration with standard monitoring stacks (Prometheus + Grafana).

## Planned Metrics

- **Case counts** -- Total cases by status, type, and age.
- **Processing times** -- Average and percentile processing times per case type.
- **Deadline compliance** -- Percentage of cases resolved within their deadline.
- **Queue depth** -- Number of unassigned cases in the werkvoorraad.
- **User activity** -- Active case workers and their current workload.
- **API response times** -- Latency of API endpoints.
- **Error rates** -- API error rates and types.
- **Storage usage** -- Document storage consumption.

## Integration

- Standard `/metrics` endpoint for Prometheus scraping.
- Grafana dashboard templates for common monitoring views.
- Alert rules for SLA compliance monitoring.

## Status

This feature is defined in the spec at `openspec/specs/prometheus-metrics/spec.md` and is planned for future implementation.
