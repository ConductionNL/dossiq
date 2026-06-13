---
kind: code
depends_on:
  - leverancier-zaakportaal-03-rbac-user-management
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

# Proposal: leverancier-zaakportaal — Member 04: Supplier Scope & API Security (code)

Member **4 of 16**. Depends on member 03 (RBAC). Implements the cross-cutting supplier-scoping
service plus the auth/rate-limit/audit-logging middleware every downstream data endpoint relies
on. This member lands BEFORE the data-feature members so 05–14 plug into a proven scope guard.

## Why

The portal must never leak one supplier's data to another (AVG art. 25/32, REQ-009-C). A single
scope service and middleware stack — applied uniformly — is the long-term unification point rather
than per-controller checks.

## What Changes

1. `SupplierScopeService` + `CaseSupplierLookup` — current-supplier resolution, scoped case
   queries, per-object access validation.
2. `SupplierAuthMiddleware`, `RateLimitMiddleware`, `AuditLoggingMiddleware` — 401/403/429 and
   masked audit logging.

## Out of Scope (this member)

Feature-specific services (tenders/invoices/contracts/KPI) — they consume this scope service in
members 05–14.

## Dependencies

- **leverancier-zaakportaal-03-rbac-user-management** (REQUIRED) — role context on the session

## Traceability

Derives from giant tasks 3.1 (supplier scope) and 4.1 (API security); spec REQ-009-B/C.
