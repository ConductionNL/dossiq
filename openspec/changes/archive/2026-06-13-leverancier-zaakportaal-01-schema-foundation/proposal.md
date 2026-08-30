---
kind: config
depends_on: []
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

# Proposal: leverancier-zaakportaal — Member 01: Schema Foundation (config)

Member **1 of 16** in the `leverancier-zaakportaal` chain. Predecessor: none (this is the
declare-first config member). This member declares the seven OpenRegister schemas the whole
supplier-portal feature reads and writes — `Supplier`, `SupplierUser`, `SupplierTender`,
`SupplierContract`, `SupplierInvoice`, `SupplierMessage`, `SupplierKPI` — together with the four
Procest supplier case types, an idempotent seed-data repair step, and an integration test that
proves the materialised records and their cross-references are correct. Once this merges, every
downstream code member (02–16) reads these fields without re-declaring them (ADR-032
expand-then-contract).

## Why

Dutch municipalities have hundreds to thousands of suppliers who need constant visibility into
their tenders, invoices, and contracts. The supplier portal automates that self-service; every
behaviour starts from a verifiable, relational data model — which this member establishes
declaratively (ADR-031 declarative-first, ADR-001 OpenRegister ObjectService). Cases visible to
suppliers (tenders, contracts, invoices) are stored as zaak subtypes in Procest and read via
OpenRegister REST.

## What Changes

1. **Seven new OpenRegister schemas** registered via the procest register on install:
   `Supplier`, `SupplierUser`, `SupplierTender`, `SupplierContract`, `SupplierInvoice`,
   `SupplierMessage`, `SupplierKPI`, including the relations between them.
2. **Four Procest supplier case types** declared: `Leverancier-contractverlenging-verzoek`,
   `Leverancier-IBAN-wijziging`, `Leverancier-accreditatie-verificatie`, `Leverancier-mutatie`.
3. **Idempotent seed data** — 3 suppliers, 5 supplier users (multi-role), 5 tenders, 4 contracts,
   5 invoices, 1 message thread — created through a repair step.
4. **Integration test** verifying materialised records exist with correct cross-references and
   indexes on `supplierRef`, `status`, and date fields.

## Out of Scope (this member)

All behaviour — authentication, RBAC, tender/invoice/contract visibility, messaging, master-data
mutations, KPI aggregation, the portal UI — lands in members 02–16. This member only declares the
schema metadata, case types, and seeds reference data.

## Dependencies

- **procest base** (REQUIRED) — zaaktype, zaak infrastructure
- **openregister** (REQUIRED) — schema registration + ObjectService

## Traceability

Derives from giant tasks 6.1 (schema seeding), 4.3 (Procest case-type definitions), and 6.2
(config) of `leverancier-zaakportaal`.
