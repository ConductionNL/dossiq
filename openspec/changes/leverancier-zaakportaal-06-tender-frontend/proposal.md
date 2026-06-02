---
kind: code
depends_on:
  - leverancier-zaakportaal-05-tender-backend
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

# Proposal: leverancier-zaakportaal — Member 06: Tender Frontend (code)

Member **6 of 16**. Depends on member 05 (tender backend). Builds the Vue tender list and detail
views: sortable/filterable list with status badges, conditional award/rejection rendering, and
document download buttons.

## Why

Suppliers need a usable UI on top of the tender API. NL Design System components and WCAG 2.1 AA
are required (ADR-010).

## What Changes

1. `TenderList` + `TenderDetail` + `TenderStatusBadge` Vue components.
2. Tender list/detail pages binding to the member 05 API with caching, sorting, filtering.

## Out of Scope (this member)

Tender backend (member 05). The dashboard summary card (member 15).

## Dependencies

- **leverancier-zaakportaal-05-tender-backend** (REQUIRED) — tender API

## Traceability

Derives from giant task 2.2 (tender list and detail view); spec REQ-003.
