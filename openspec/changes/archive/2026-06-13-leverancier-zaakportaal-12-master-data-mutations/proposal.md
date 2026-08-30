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

# Proposal: leverancier-zaakportaal — Member 12: Master Data Self-Service (code)

Member **12 of 16**. Depends on member 04 (scope). Implements "Mijn Gegevens": address/contact
auto-apply, IBAN change with re-auth + 4-eyes Procest workflow, and SBI/accreditation submission
for verification, plus the profile UI and IBAN verification flow.

## Why

Suppliers must self-service routine master-data changes while sensitive ones (IBAN) go through
re-auth and 4-eyes review — the long-term unified write path is one mutation service with a
per-field approval policy. Full slice (service + job + controller + Vue), ~16 tasks.

## What Changes

1. `SupplierMasterDataMutationService` — updateAddress, updateContactPerson, requestIBANChange,
   submitForVerification.
2. `ProfileController` + `ProcessMasterDataMutationsJob`.
3. `ProfileForm` + `IBANVerificationFlow` Vue components.

## Out of Scope (this member)

The 4-eyes approval execution inside Procest (Procest). Bank verification client (OpenConnector).

## Dependencies

- **leverancier-zaakportaal-04-supplier-scope-security** (REQUIRED) — scope + middleware + audit

## Traceability

Derives from giant tasks 3.5 (master-data mutation service) and 2.6 (Mijn Gegevens UI); spec
REQ-007.
