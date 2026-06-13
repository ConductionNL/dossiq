---
kind: code
depends_on: [tenant-zaaksysteem-saas-01-schemas-and-seed]
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

# Proposal: tenant-zaaksysteem-saas-02-tenant-crud-lifecycle

Member 2 of 12 in the **tenant-zaaksysteem-saas** chain (ADR-032). Predecessor: `tenant-zaaksysteem-saas-01-schemas-and-seed`. This `kind: code` member consumes the `Tenant` schema declared in member 01 to provide the tenant CRUD API and the lifecycle state machine (onboarding → active → suspended → terminated), including auto-generated unique slugs.

## Why

Every multi-tenant capability downstream — provisioning, isolation, onboarding, quotas, billing — keys off a `Tenant` record with a stable slug and a well-defined status. The CRUD API and lifecycle transitions are the entry point a SaaS administrator uses to create and manage tenants, so they must land before any consumer that reads tenant status.

## What Changes (this member)

1. `TenantService` with OpenRegister `ObjectService`-backed CRUD: `create()`, `getById()`, `listActive()`, `updateStatus()`.
2. Slug auto-generation (lowercased, hyphens, max 64 chars) with a uniqueness guard.
3. `TenantController` with POST/GET/PATCH/DELETE endpoints, list filtering by status, and an admin-only authorization posture (ADR-005).
4. Lifecycle state-machine validation for the status enum (legal transitions only).

## Impact

- **Affected**: procest (`TenantService`, `TenantController`).
- **Traces to giant tasks**: Task 1 (Tenant CRUD API, slug generation, list filtering, CRUD tests). The suspend/terminate transitions themselves are member 11; this member only establishes the lifecycle state machine and the `updateStatus` primitive.
- **Depends on**: member 01 `Tenant` schema.
