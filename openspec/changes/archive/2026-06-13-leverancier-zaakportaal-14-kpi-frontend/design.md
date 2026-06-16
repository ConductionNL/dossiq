# Design — Member 14: KPI Frontend (code)

## Scope

Vue KPI dashboard consuming the member 13 API. Frontend-only.

## Declarative-first (ADR-031) note

No new schema or backend behaviour. Route declared in the app manifest (ADR-024); this member adds
the chart/card components.

## Approach

- `KPICard` — metric title, current value, benchmark comparison (↓ better / ↑ worse), embedded
  trend chart.
- `TrendChart` — line for payment days and on-time %, bar for dispute rate; X-axis month labels,
  metric-specific Y-axis, hover tooltip ("May 2026: 28 days"); months marked insufficient are
  skipped.
- CSV export button calls GET /kpis/export and triggers the download.

## Security (ADR-005)

All data from the scoped member 13 API; the UI renders only the supplier's own metrics plus the
anonymous municipal benchmark. NL Design System + WCAG 2.1 AA (ADR-010).
