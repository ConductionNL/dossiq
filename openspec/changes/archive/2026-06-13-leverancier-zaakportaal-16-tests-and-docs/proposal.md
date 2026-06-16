---
kind: code
depends_on:
  - leverancier-zaakportaal-15-dashboard-shell
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

# Proposal: leverancier-zaakportaal — Member 16: Cross-Cutting Tests, A11y/Security Audit & Docs (code)

Member **16 of 16** (final). Depends on member 15 (shell). Adds the cross-cutting test pyramid
(unit, integration, E2E), the WCAG 2.1 AA + security audit, and the portal documentation/release
plan that span all prior members.

## Why

Per-member tests live in their own members; this final member adds the end-to-end journeys, the
formal accessibility/security audit, and the API/deployment/user docs that only make sense once
the whole portal exists (ADR-008 testing, ADR-009 documentation).

## What Changes

1. E2E supplier-journey tests (login → cases → message → profile; multi-user invite/role).
2. WCAG 2.1 AA audit + security audit (XSS/CSRF/injection, scope-leak, rate-limit, audit-log).
3. API docs, deployment guide, user guide, release/rollback plan.

## Out of Scope (this member)

Per-member unit/component tests already shipped in members 02–15.

## Dependencies

- **leverancier-zaakportaal-15-dashboard-shell** (REQUIRED) — full portal present

## Traceability

Derives from giant tasks 5.1–5.6 (testing + audits), 6.3 (documentation), and 6.4 (release plan).
