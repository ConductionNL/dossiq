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

# Proposal: leverancier-zaakportaal — Member 07: Invoice Payment Forecast Backend (code)

Member **7 of 16**. Depends on member 04 (scope). Implements the invoice payment-forecast service:
expected payment date from Decidesk mandate routing + payment terms, age-analysis buckets, the
90+ overdue alert job, and the dispute endpoint.

## Why

Suppliers' top question is "where is my invoice / when will I be paid?". The forecast joins
Decidesk mandate routing with payment terms. Backend here; UI in member 08.

## What Changes

1. `InvoicePaymentForecastService` — expected payment date, age analysis, dispute.
2. `InvoiceController` — GET /invoices, /invoices/{id}, /invoices/age-analysis, POST
   /invoices/{id}/dispute.
3. Nightly job flagging 90+ day overdue invoices and emailing the supplier.

## Out of Scope (this member)

The Vue invoice UI (member 08). The Decidesk mandate-routing algorithm itself (Decidesk).

## Dependencies

- **leverancier-zaakportaal-04-supplier-scope-security** (REQUIRED) — scope + middleware
- **decidesk** (REQUIRED) — mandate routing delay lookup

## Traceability

Derives from giant tasks 3.3 (invoice forecast service) and 4.4 (Decidesk integration); spec
REQ-004.
