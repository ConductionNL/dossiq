# supplier-portal Specification — Member 14: KPI Frontend

---
status: proposed
---

## Purpose

Render the KPI dashboard consuming the member 13 API, with metric cards, 12-month trend charts,
benchmark comparison, and CSV export.

## ADDED Requirements

### Requirement: KPI Cards and Trend Charts

The system SHALL display four metric cards with benchmark comparison and 12-month trend charts.

#### Scenario: Cards render with benchmark and trends

- GIVEN a user with admin or finance role opens the KPI Dashboard
- WHEN it loads from the KPI API
- THEN four cards SHALL show avg payment days, on-time %, dispute rate, and compliance score
- AND each card SHALL show the municipal benchmark comparison and a 12-month trend chart with month
  labels and hover tooltips

#### Scenario: Insufficient-data months are skipped from the chart

- GIVEN a month is flagged `sufficientData` = false
- WHEN the trend chart renders
- THEN that month SHALL be skipped from the chart and labelled insufficient

### Requirement: CSV Export UI

The system SHALL provide a CSV export action on the KPI dashboard.

#### Scenario: Export downloads the CSV

- GIVEN the KPI dashboard is displayed
- WHEN the user clicks "Export to CSV"
- THEN the browser SHALL download the CSV served by the KPI export endpoint
