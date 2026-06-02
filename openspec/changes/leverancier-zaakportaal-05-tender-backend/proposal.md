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

# Proposal: leverancier-zaakportaal — Member 05: Tender Visibility Backend (code)

Member **5 of 16**. Depends on member 04 (scope). Implements the tender visibility service and
scoped API: status, award/rejection fields, appeal-deadline calculation, and anonymized
evaluation-report + award-letter download (Aanbestedingswet 2012 art. 2.130).

## Why

Suppliers need real-time tender status and the legally mandated rejection motivation + appeal
window. This member is the backend; the UI lands in member 06.

## What Changes

1. `TenderVisibilityService` — status, appeal deadline (20 days), evaluation report, award letter.
2. `TenderController` — GET /tenders, /tenders/{id}, /tenders/{id}/evaluation-report, scoped via
   member 04 middleware.

## Out of Scope (this member)

The Vue tender list/detail UI (member 06). PDF generation is Docudesk; this serves the existing
anonymized PDF.

## Dependencies

- **leverancier-zaakportaal-04-supplier-scope-security** (REQUIRED) — scope + middleware

## Traceability

Derives from giant task 3.2 (tender visibility service); spec REQ-003.
