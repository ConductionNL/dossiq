---
kind: code
depends_on:
  - leverancier-zaakportaal-09-contract-backend
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

# Proposal: leverancier-zaakportaal — Member 10: Contract Frontend (code)

Member **10 of 16**. Depends on member 09 (contract backend). Builds the Vue contract list and
detail UI: sort by nearest expiry, orange warning badges within 90 days, renewal-option display,
and the "Verlenging aanvragen" modal.

## Why

Suppliers need a clear view of which contracts are expiring and a one-click renewal request. UI on
top of the member 09 API.

## What Changes

1. `ContractList` + `ContractDetail` + `RenewalRequestModal` Vue components.
2. Contract pages with expiry-warning badges and the renewal-request flow.

## Out of Scope (this member)

Contract backend (member 09). The dashboard summary card (member 15).

## Dependencies

- **leverancier-zaakportaal-09-contract-backend** (REQUIRED) — contract API

## Traceability

Derives from giant task 2.4 (contract list, detail, renewal workflow); spec REQ-005.
