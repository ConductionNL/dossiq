---
kind: config
depends_on: []
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

# Proposal: tenant-zaaksysteem-saas-01-schemas-and-seed

Member 1 of 12 in the **tenant-zaaksysteem-saas** chain (ADR-032 decomposition of the original ~206-task giant). This is the foundational `kind: config` member; it has no predecessor. It declares the seven OpenRegister schemas that the whole multi-tenant feature consumes, registers them through the procest register template, ships seed tier-template and default-tenant data, and adds an integration test verifying the materialised schema fields. Every later `kind: code` member depends (transitively) on this member.

## Why

Procest-as-a-SaaS turns a single-instance case-management system into a platform serving 50+ municipalities with strict data segregation, per-tenant configuration, quotas, and billing. Per ADR-031 (declarative-first) and ADR-001 (OpenRegister data layer), the tenant data model is declared as register schemas first so all consumers — context middleware, onboarding, quota enforcement, billing — read the same canonical shape. Declaring the model before any service code lets the cross-schema relations and the OpenRegister multi-tenant integration (REQ-009) surface and stabilise in isolation, exactly the expand-then-contract property ADR-032 buys.

## What Changes (this member)

1. Declare seven OpenRegister schemas: `Tenant`, `TenantConfiguration`, `TenantQuota`, `TenantUser`, `TenantMandate`, `TenantBillingEvent`, `TenantOnboardingTask`.
2. Register them via the procest register template (`lib/Settings/*_register.json` + repair-step import per the fleet pattern).
3. Seed tier quota-limit templates (basic/standard/enterprise) and a default-tenant template the onboarding flow forks from.
4. Add an integration test verifying the materialised schemas expose the documented properties and that the OpenRegister multi-tenant REST queries return tenant-scoped results (REQ-009 schema-materialisation slice).

## Impact

- **Affected**: procest (schema declarations + seed), openregister (register template / REST API consumer, multi-tenant query scope).
- **Traces to giant tasks**: Task 1 (Tenant entity schema), Task 10 (TenantConfiguration schema), Task 13 (TenantQuota schema + tier limits), Task 16 (TenantBillingEvent schema), Task 7 (TenantOnboardingTask schema), Task 23 (OpenAPI schema definitions — declarative slice), REQ-009 (multi-tenant OpenRegister integration).
- **Standards**: Common Ground layer 1 (data), AVG artikel 28, ADR-001/ADR-031.
