---
kind: code
depends_on: [tenant-zaaksysteem-saas-02-tenant-crud-lifecycle]
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

# Proposal: tenant-zaaksysteem-saas-03-schema-provisioning

Member 3 of 12 in the **tenant-zaaksysteem-saas** chain (ADR-032). Predecessor: `tenant-zaaksysteem-saas-02-tenant-crud-lifecycle`. This `kind: code` member adds the PostgreSQL schema-per-tenant provisioning service that creates a tenant's isolated database schema, clones the application tables, seeds default zaaktype templates and roles, and (for enterprise) supports the database-per-tenant option.

## Why

Schema-per-tenant is the default isolation mechanism (REQ-001-B); a tenant cannot accept any case data until its schema exists with the application tables cloned and default roles seeded. Provisioning must run after a tenant record exists (member 02) and before the context middleware (member 04) can set a search_path that points at it.

## What Changes (this member)

1. `TenantProvisioningService.provision(tenantId)` — creates schema `tenant_{uuid}_{slug}` (≤63 chars), clones application table structures from public.
2. Seeds standard zaaktype templates, default mandaat-matrix template, and default roles (tenant_admin, case_handler, viewer) into the tenant schema.
3. Sends a welcome email to the tenant admin with a login link.
4. Rollback on provisioning failure; database-per-tenant option for enterprise tier (Phase 2 path).

## Impact

- **Affected**: procest (`TenantProvisioningService`, `TenantSchemaProvisioner`).
- **Traces to giant tasks**: Task 2 (schema provisioning, cloning, seeding, rollback, provisioning tests), REQ-001-B, REQ-001-C.
- **Depends on**: member 02 (Tenant record) and member 01 (tenant schemas + seed templates).
