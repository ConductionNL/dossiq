---
kind: code
depends_on: [tenant-zaaksysteem-saas-03-schema-provisioning]
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

# Proposal: tenant-zaaksysteem-saas-04-tenant-context-isolation

Member 4 of 12 in the **tenant-zaaksysteem-saas** chain (ADR-032). Predecessor: `tenant-zaaksysteem-saas-03-schema-provisioning`. This `kind: code` member wires the request-scoped tenant context and the isolation middleware that sets the PostgreSQL `search_path` per request, guaranteeing query-level isolation between tenants.

## Why

Provisioned schemas (member 03) are inert until something points queries at them per request. The context + isolation middleware is the single chokepoint that makes every database query tenant-scoped; it is the load-bearing data-leak-prevention control (REQ-002-A) that every downstream consumer relies on.

## What Changes (this member)

1. `TenantContext` request-scoped singleton holding tenant_id, schema name, and the resolved Tenant object.
2. `TenantContextMiddleware` resolves the Tenant record from the request's tenant_id.
3. `TenantIsolationMiddleware` sets `SET search_path = 'public,tenant_{uuid}_{slug}'` before any query runs.
4. Cross-tenant query attempts resolve to 0 rows (HTTP 404, not 403, to avoid leaking tenant existence); query-performance benchmark with search_path overhead.

## Impact

- **Affected**: procest (`TenantContext`, `TenantContextMiddleware`, `TenantIsolationMiddleware`, middleware registration).
- **Traces to giant tasks**: Task 3 (context middleware, isolation middleware, search_path, cross-tenant 404, performance benchmark), REQ-002-A.
- **Depends on**: member 03 (provisioned schemas) — the source of tenant_id (JWT) is member 05, which depends on this member for the context it populates.
