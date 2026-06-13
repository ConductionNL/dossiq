---
kind: code
depends_on:
  - leverancier-zaakportaal-04-supplier-scope-security
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

# Proposal: leverancier-zaakportaal — Member 09: Contract Renewal Backend (code)

Member **9 of 16**. Depends on member 04 (scope). Implements the contract renewal service: scan
for contracts within 90 days of expiry, set `renewalWarning`, the renewal-request flow that opens
a Procest zaak, and the nightly expiry-scan job.

## Why

Suppliers must be warned before a contract lapses and be able to request renewal in one click. The
renewal request becomes a Procest case routed to the account manager. Backend here; UI in member
10.

## What Changes

1. `ContractRenewalService` — scan expiring, flag within threshold, request renewal.
2. `ContractController` — GET /contracts, /contracts/{id}, POST /contracts/{id}/request-renewal.
3. `ScanExpiringContractsJob` — nightly at 03:00 UTC, emails suppliers with expiring contracts.

## Out of Scope (this member)

The Vue contract UI (member 10). The Procest workflow itself (Procest); this member creates the
zaak via REST.

## Dependencies

- **leverancier-zaakportaal-04-supplier-scope-security** (REQUIRED) — scope + middleware

## Traceability

Derives from giant task 3.4 (contract renewal service); spec REQ-005.
