---
kind: code
depends_on:
  - leverancier-zaakportaal-14-kpi-frontend
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

# Proposal: leverancier-zaakportaal — Member 15: Dashboard Shell & Layout (code)

Member **15 of 16**. Depends on member 14 (last feature UI). Builds the portal shell: layout,
role-aware navigation, the dashboard summary cards that aggregate tenders/invoices/contracts/KPI,
the user profile menu, responsive design, and accessibility scaffolding. Landing last lets the
shell link to every feature view that now exists.

## Why

The shell ties the feature views (06/08/10/14) together with a consistent NL Design System layout
and the at-a-glance summary cards. It is the home page suppliers land on after login.

## What Changes

1. `PortalLayout` + `NavBar` + `DashboardSummary` Vue components.
2. Dashboard page with 4 summary cards, role-aware nav, profile menu, responsive + a11y scaffolding.

## Out of Scope (this member)

Feature backends/UIs (members 02–14). Tests and docs (member 16).

## Dependencies

- **leverancier-zaakportaal-14-kpi-frontend** (REQUIRED) — final feature view to link from the shell

## Traceability

Derives from giant task 2.1 (dashboard and layout); spec REQ-003/004/005/008 summary surfaces.
