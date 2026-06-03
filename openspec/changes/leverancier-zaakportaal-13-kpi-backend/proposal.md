---
kind: code
depends_on:
  - leverancier-zaakportaal-07-invoice-backend
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

# Proposal: leverancier-zaakportaal — Member 13: KPI Aggregation Backend (code)

Member **13 of 16**. Depends on member 07 (invoice backend — the source of payment data).
Implements the nightly KPI aggregation: avg payment days, on-time %, dispute rate, compliance
score, municipal benchmark, insufficient-data handling, and the snapshot/trends/export API.

## Why

Suppliers want a 12-month performance view with a municipal benchmark. KPI is computed nightly
from invoice data. Backend here; UI in member 14.

## What Changes

1. `SupplierKPIAggregationService` — four metric calculators + benchmark + insufficient-data.
2. `AggregateSupplierKPIsJob` — nightly at 02:00 UTC.
3. `KPIController` — GET /kpis, /kpis/trends, /kpis/export (CSV).

## Out of Scope (this member)

The Vue KPI charts (member 14).

## Dependencies

- **leverancier-zaakportaal-07-invoice-backend** (REQUIRED) — paid/disputed invoice data
- **leverancier-zaakportaal-04-supplier-scope-security** (transitive) — scope + middleware

## Traceability

Derives from giant task 3.7 (KPI aggregation service); spec REQ-008-D.
