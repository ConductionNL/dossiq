---
kind: code
depends_on:
  - leverancier-zaakportaal-02-eherkenning-auth
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

# Proposal: leverancier-zaakportaal — Member 03: Multi-User RBAC (code)

Member **3 of 16**. Depends on member 02 (auth). Implements supplier-admin user management:
invite team members by email + role, activate via eHerkenning, change roles, revoke access, and
enforce role-based dashboard tab visibility.

## Why

A supplier organisation has multiple people (finance, sales, contracts, read-only). Admins must
self-service who can see what. This member adds the invitation/activation/role lifecycle and the
role guard on top of the session from member 02.

## What Changes

1. `SupplierUserManagementService` — invite, activate, update role, revoke.
2. `UserManagementController` — activation, role, revoke endpoints.
3. `DashboardTabs` component + `roleGuard` middleware enforcing the role→tab matrix.

## Out of Scope (this member)

Per-object supplier data scoping (member 04). Notification email transport is wired here for
invites; the broader notification integration is shared across members.

## Dependencies

- **leverancier-zaakportaal-02-eherkenning-auth** (REQUIRED) — session + SupplierUser linking

## Traceability

Derives from giant task 1.2 (RBAC) and the invite portion of 4.5 (notifications); spec REQ-002.
