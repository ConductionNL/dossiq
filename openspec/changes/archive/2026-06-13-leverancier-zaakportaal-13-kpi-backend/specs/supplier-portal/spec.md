# supplier-portal Specification — Member 13: KPI Aggregation Backend

---
status: proposed
---

## Purpose

Aggregate supplier KPI metrics nightly with a municipal benchmark and serve snapshot/trends/export.
Reads `SupplierInvoice`/`SupplierContract` and writes `SupplierKPI` (member 01 schemas).

## ADDED Requirements

### Requirement: Nightly KPI Aggregation

The system SHALL compute four KPI metrics per supplier per month with a municipal benchmark and
mark months with insufficient data.

#### Scenario: Metrics and benchmark are computed for a month

- GIVEN the KPI aggregation job runs nightly at 02:00 UTC
- WHEN it processes a supplier's prior month
- THEN it SHALL store a `SupplierKPI` record with avg payment days, on-time %, dispute rate, and
  compliance score, plus the municipal benchmark for the same period

#### Scenario: Insufficient data is flagged

- GIVEN a supplier has fewer than 3 invoices in a month
- WHEN the aggregation runs
- THEN that month's metric SHALL be marked `sufficientData` = false and excluded from the trend

### Requirement: KPI Snapshot, Trends, and Export API

The system SHALL serve the current snapshot, 12-month trends, and a CSV export over a scoped API.

#### Scenario: CSV export contains 12 months per metric

- GIVEN a supplier with 12 months of data requests an export
- WHEN the export endpoint responds
- THEN the CSV SHALL contain 48 data rows (4 metrics × 12 months) plus a header, with values to one
  decimal and ISO `YYYY-MM` dates
- AND the export event SHALL be audit-logged
