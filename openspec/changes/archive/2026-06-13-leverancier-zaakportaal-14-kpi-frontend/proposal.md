---
kind: code
depends_on:
  - leverancier-zaakportaal-13-kpi-backend
chain:
  - leverancier-zaakportaal-01-schema-foundation
  - leverancier-zaakportaal-02-eherkenning-auth
  - leverancier-zaakportaal-03-rbac-user-management
  - leverancier-zaakportaal-04-supplier-scope-security
  - leverancier-zaakportaal-05-tender-backend
  - leverancier-zaakportaal-06-tender-frontend
  - leverancier-zaakportaal-07-invoice-backend
  - leverancier-zaakportaal-08-invoice-frontend
  - leverancier-zaakportaal-09-contract-backend
  - leverancier-zaakportaal-10-contract-frontend
  - leverancier-zaakportaal-11-messaging
  - leverancier-zaakportaal-12-master-data-mutations
  - leverancier-zaakportaal-13-kpi-backend
  - leverancier-zaakportaal-14-kpi-frontend
  - leverancier-zaakportaal-15-dashboard-shell
  - leverancier-zaakportaal-16-tests-and-docs
---

# Proposal: leverancier-zaakportaal — Member 14: KPI Frontend (code)

Member **14 of 16**. Depends on member 13 (KPI backend). Builds the Vue KPI dashboard: four metric
cards with benchmark comparison, 12-month trend charts, tooltips, insufficient-data handling, and
CSV export.

## Why

Suppliers need the KPI numbers visualised with trends and benchmark, plus export. UI on top of the
member 13 API.

## What Changes

1. `KPICard` + `TrendChart` + `MetricCard` Vue components.
2. KPI page binding snapshot + trends, with the export button.

## Out of Scope (this member)

KPI backend (member 13). The dashboard KPI headline card (member 15).

## Dependencies

- **leverancier-zaakportaal-13-kpi-backend** (REQUIRED) — KPI API

## Traceability

Derives from giant task 2.7 (KPI dashboard UI); spec REQ-008-A/B/C.
