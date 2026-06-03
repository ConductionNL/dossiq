---
kind: code
depends_on: [tenant-zaaksysteem-saas-05-auth-jwt-tenant-claim]
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

# Proposal: tenant-zaaksysteem-saas-06-mandate-validation

Member 6 of 12 in the **tenant-zaaksysteem-saas** chain (ADR-032). Predecessor: `tenant-zaaksysteem-saas-05-auth-jwt-tenant-claim`. This `kind: code` member adds per-tenant mandate-matrix (mandaat-matrix) validation: it checks a user's eHerkenning role against the tenant's mandate for the requested action and blocks unauthorised actions with an audit-logged 403.

## Why

Tenant isolation (member 04) and authentication (member 05) prove *which tenant* a user belongs to, but not *what they may do* within it. Dutch eHerkenning mandaat-matrix semantics require an action-level authorisation check (REQ-002-D, REQ-006-D): a Behandelaar may edit a case but not necessarily transition its status. This member is the action-level authorization control (ADR-023) for the multi-tenant context.

## What Changes (this member)

1. `TenantAuthenticationService.validateMandateMatrix(tenantId, userId, action)` loads the tenant's `TenantMandate` and checks the user's role against the matrix, returning `{allowed, reason}`.
2. `MandateValidationMiddleware` invokes the check on requests that require a mandate (case edit, status transition).
3. Audit logging of every mandate decision (allow/deny) for the compliance trail.

## Impact

- **Affected**: procest (`TenantAuthenticationService`, `MandateValidationMiddleware`).
- **Traces to giant tasks**: Task 6 (mandate-matrix validation service + middleware + audit logging + role/action tests), REQ-002-D, REQ-006-D.
- **Depends on**: member 05 (the JWT roles + tenant_id the check reads) and member 01 (`TenantMandate` schema).
