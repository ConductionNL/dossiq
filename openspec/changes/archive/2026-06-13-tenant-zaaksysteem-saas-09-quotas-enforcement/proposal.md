---
kind: code
depends_on: [tenant-zaaksysteem-saas-08-configuration-branding]
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

# Proposal: tenant-zaaksysteem-saas-09-quotas-enforcement

Member 9 of 12 in the **tenant-zaaksysteem-saas** chain (ADR-032). Predecessor: `tenant-zaaksysteem-saas-08-configuration-branding`. This `kind: code` member implements per-tenant resource quotas: the quota service (tier-based limits + initialisation on go-live), the real-time enforcement middleware (warn/throttle/block + 80% soft-limit warnings), and the monthly reset background job.

## Why

Quotas are the SaaS commercial control: they cap usage per tier, drive upgrade prompts, and feed billing overage events (member 10). Go-live (member 07) triggers quota initialisation, so the quota service must exist by then; enforcement is the runtime gate on case creation and API calls.

## What Changes (this member)

1. `TenantQuotaService` (initialize from tier templates, getQuota, checkLimit, increment) + `TenantQuotaController` (GET list, PATCH limits for admin), wired into go-live initialisation.
2. `QuotaEnforcementMiddleware` checks the quota before case creation / API calls; enforcement modes warn/throttle/block (429 on block); 80% soft-limit warning emails.
3. `ResetMonthlyQuotas` background job resets monthly quota usage where `resetAt < today`; tier-upgrade takes effect within 1 minute.

## Impact

- **Affected**: procest (`TenantQuotaService`, `TenantQuotaController`, `QuotaEnforcementMiddleware`, `ResetMonthlyQuotas` job).
- **Traces to giant tasks**: Task 13 (quota schema service + tier limits), Task 14 (enforcement middleware), Task 15 (monthly reset job), REQ-005-A/B/C/D/E.
- **Depends on**: member 08 (configuration), member 07 (go-live triggers init), member 01 (`TenantQuota` schema + tier templates).
