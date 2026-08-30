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

# Proposal: leverancier-zaakportaal — Member 08: Invoice Frontend (code)

Member **8 of 16**. Depends on member 07 (invoice backend). Builds the Vue invoice list, detail,
and age-analysis UI: status badges, expected-payment-date display, the stacked age-analysis bar
with bucket filtering, and dispute composer entry.

## Why

Suppliers need to scan invoice status and payment forecast at a glance, and drill into overdue
buckets. UI on top of the member 07 API.

## What Changes

1. `InvoiceList` + `InvoiceDetail` + `AgeAnalysisBar` Vue components.
2. Invoice list/detail pages with filtering and the green expected-payment-date box.

## Out of Scope (this member)

Invoice backend (member 07). The dashboard summary card (member 15). The message composer
(member 11) is reused for disputes.

## Dependencies

- **leverancier-zaakportaal-07-invoice-backend** (REQUIRED) — invoice API

## Traceability

Derives from giant task 2.3 (invoice list, detail, age analysis); spec REQ-004.
