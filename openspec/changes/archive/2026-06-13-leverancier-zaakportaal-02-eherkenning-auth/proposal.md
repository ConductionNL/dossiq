---
kind: code
depends_on:
  - leverancier-zaakportaal-01-schema-foundation
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

# Proposal: leverancier-zaakportaal — Member 02: eHerkenning Authentication (code)

Member **2 of 16**. Depends on member 01 (schema foundation). Consumes the `Supplier` and
`SupplierUser` schemas to implement eHerkenning niveau 2+ login: redirect to the OpenConnector
broker, KvK-claim validation, automatic SupplierUser creation/linking, and session issuance with
a 2-hour TTL plus a financial-re-auth flag.

## Why

Suppliers authenticate as corporate entities via eHerkenning (KvK-based). This member establishes
the trusted session every other portal capability relies on. It is `code` (PHP service +
controller + Vue login/callback pages) with no new declarative surface beyond what member 01
declared.

## What Changes

1. `SupplierAuthService` — exchange code, validate KvK claim, create/link `SupplierUser`, issue
   session token (2h TTL).
2. `AuthController` — `/auth/eherkenning-login`, `/auth/callback`, `/auth/logout`,
   `/auth/refresh`.
3. Login + callback Vue pages and a session middleware that triggers re-auth on expiry.

## Out of Scope (this member)

Role-based tab visibility (member 03), supplier scoping of data endpoints (member 04). The
eHerkenning broker and KvK API client live in OpenConnector (coordination only).

## Dependencies

- **leverancier-zaakportaal-01-schema-foundation** (REQUIRED) — `Supplier`, `SupplierUser` schemas
- **openconnector** (REQUIRED) — eHerkenning broker + KvK API

## Traceability

Derives from giant tasks 1.1 (eHerkenning login) and 4.2 (eHerkenning/KvK coordination); spec
REQ-001.
