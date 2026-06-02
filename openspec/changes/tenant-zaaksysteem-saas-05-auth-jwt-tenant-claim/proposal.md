---
kind: code
depends_on: [tenant-zaaksysteem-saas-04-tenant-context-isolation]
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

# Proposal: tenant-zaaksysteem-saas-05-auth-jwt-tenant-claim

Member 5 of 12 in the **tenant-zaaksysteem-saas** chain (ADR-032). Predecessor: `tenant-zaaksysteem-saas-04-tenant-context-isolation`. This `kind: code` member injects the tenant claim into the JWT at login (incl. via eHerkenning SAML) and validates it on every request, rejecting forged or cross-tenant tokens.

## Why

The isolation middleware (member 04) needs an authenticated, trustworthy tenant_id to scope queries. JWT signature validation + tenant-claim injection is what makes that tenant_id trustworthy: a forged token is rejected (401), and a valid token presented against a different tenant's domain is rejected (403) and logged. This member is the authentication half of the per-tenant SSO requirement; the IdP-endpoint configuration UI is member 08, mandate checks are member 06.

## What Changes (this member)

1. Enhance `AuthenticationService` to accept tenant context at login and inject `tenant_id` + `tenant_slug` into the JWT (including a `createTokenFromSAML()` path for eHerkenning).
2. Enhance `AuthenticateMiddleware` to validate the JWT signature and extract tenant_id; reject forged tokens (401).
3. `TenantClaimValidationMiddleware` compares the JWT tenant_id against the request tenant (URL/domain/header); mismatch → 403.
4. Security logging of cross-tenant attempts + rate-limit alert after 5 failures/hour from one IP.

## Impact

- **Affected**: procest (`AuthenticationService`, `AuthenticateMiddleware`, `TenantClaimValidationMiddleware`).
- **Traces to giant tasks**: Task 4 (JWT tenant-claim injection), Task 5 (claim-validation middleware + security logging + rate limiting), REQ-002-B/C, REQ-006-B/C.
- **Depends on**: member 04 (the context the claim populates).
