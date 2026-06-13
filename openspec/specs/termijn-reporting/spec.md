---
status: done
status-note: Reverse-synced 2026-06-13 from an archived fully-implemented change; capability code confirmed present on development.
---
# termijn-reporting Specification

## Purpose
TBD - created by archiving change termijnbewaking-dwangsom-engine-09-reporting-dashboard. Update Purpose after archive.
## Requirements
### Requirement: Reporting voor management en accountant (REQ-TERM-009)

The system SHALL produce a quarterly termijn-KPI report per zaaktype and an annual dwangsom audit report for the jaarrekening, and SHALL expose an aggregated dashboard KPI endpoint.

#### Scenario: Quarterly KPI report per zaaktype

- **GIVEN** an afdelingshoofd requests the kwartaalrapport for a period
- **WHEN** the report is generated
- **THEN** the response SHALL include, per zaaktype, totaal-zaken, percentage-binnen-termijn, gemiddelde-doorlooptijd, aantal-verlengingen, aantal-overschrijdingen, aantal-ingebrekestellingen, and totaal-dwangsom-uitgekeerd
- **AND** the report SHALL be exportable as CSV/JSON with report metadata

#### Scenario: Annual dwangsom audit report

- **GIVEN** an accountant requests the jaaroverzicht for a year
- **WHEN** the report is generated
- **THEN** a CSV/JSON SHALL list all dwangsommen with zaak-referentie, zaaktype, ingebrekestelling-datum, beschikking-datum, dwangsom-bedrag, betaal-datum, betalings-referentie, and status
- **AND** the report SHALL include summary statistics (total records, total amount, count by status)

#### Scenario: Dashboard KPI endpoint returns aggregates

- **GIVEN** the dashboard requests the termijn KPI widget
- **WHEN** `GET /api/procest/dashboard/termijn-kpi` is called
- **THEN** the response SHALL return totalZaken, withinTermijnPercent, avgDurationDays, overrunCount, dwangsomTotal, and lastUpdated
- **AND** the aggregate SHALL be cached and refreshed at least hourly

