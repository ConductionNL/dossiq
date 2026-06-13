---
kind: code
depends_on: [tenant-zaaksysteem-saas-11-suspension-termination]
chain:
  - tenant-zaaksysteem-saas-01-schemas-and-seed
  - tenant-zaaksysteem-saas-02-tenant-crud-lifecycle
  - tenant-zaaksysteem-saas-03-schema-provisioning
  - tenant-zaaksysteem-saas-04-tenant-context-isolation
  - tenant-zaaksysteem-saas-05-auth-jwt-tenant-claim
  - tenant-zaaksysteem-saas-06-mandate-validation
  - tenant-zaaksysteem-saas-07-onboarding-workflow
  - tenant-zaaksysteem-saas-08-configuration-branding
  - tenant-zaaksysteem-saas-09-quotas-enforcement
  - tenant-zaaksysteem-saas-10-billing-shillinq
  - tenant-zaaksysteem-saas-11-suspension-termination
  - tenant-zaaksysteem-saas-12-isolation-tests-compliance
---

# Proposal: tenant-zaaksysteem-saas-12-isolation-tests-compliance

Member 12 of 12 (final) in the **tenant-zaaksysteem-saas** chain (ADR-032). Predecessor: `tenant-zaaksysteem-saas-11-suspension-termination`. This `kind: code` member hardens and verifies the whole feature: cross-tenant isolation integration tests, the end-to-end onboarding test, the consolidated OpenAPI documentation, the security/performance hardening pass, and the compliance/audit-trail requirements (REQ-010).

## Why

Multi-tenancy lives or dies on proven isolation; a data leak between tenants is a critical, contract-ending failure (named risk). This closing member consolidates the suite-level isolation + E2E tests that span all prior members, finalises the platform OpenAPI doc, runs the security/performance hardening checklist, and implements the audit-logging + AVG/BIO compliance requirements that earlier members emit into.

## What Changes (this member)

1. Cross-tenant isolation integration tests (`TenantIsolationTest`) and the full onboarding E2E test (`TenantOnboardingFlowTest`).
2. Consolidated OpenAPI 3.0 spec for all tenant endpoints (CRUD, onboarding, config, quotas, billing, lifecycle) with auth/webhook/error documentation.
3. Audit logging for data access (REQ-010-A), BIO 2.0 enterprise context fields (REQ-010-B), AVG deletion-on-termination confirmation (REQ-010-C).
4. Security hardening checklist + performance optimisation (indexes, slow-query profiling, load test, scaling docs).

## Impact

- **Affected**: procest (test suites, OpenAPI spec, audit-logging, hardening), CI (isolation tests mandatory).
- **Traces to giant tasks**: Task 21 (isolation tests), Task 22 (E2E onboarding), Task 23 (OpenAPI), Task 24 (security hardening), Task 25 (performance), REQ-010-A/B/C.
- **Depends on**: member 11 (terminated-tenant deletion) and, transitively, every prior member.
