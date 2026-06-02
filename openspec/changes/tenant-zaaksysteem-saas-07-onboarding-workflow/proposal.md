---
kind: code
depends_on: [tenant-zaaksysteem-saas-06-mandate-validation]
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

# Proposal: tenant-zaaksysteem-saas-07-onboarding-workflow

Member 7 of 12 in the **tenant-zaaksysteem-saas** chain (ADR-032). Predecessor: `tenant-zaaksysteem-saas-06-mandate-validation`. This `kind: code` member builds the guided onboarding workflow: the 7-step checklist + progress dashboard, the Decidesk contract-signature integration (webhook), and go-live validation that activates the tenant.

## Why

Small municipalities are deterred by complex onboarding; the guided checklist with sensible defaults is the product's adoption lever (Success Metric: MTTR < 5 min). Onboarding orchestrates the earlier members — it creates the tenant (02), triggers provisioning (03), and culminates in go-live activation. The Decidesk contract step is the legal gate before a tenant can go live.

## What Changes (this member)

1. `TenantOnboardingService.createOnboarding(tenantId)` initialises the 7-step checklist (forking the member-01 default-tenant template); `TenantOnboardingController` exposes progress + step-marking; a Vue progress dashboard renders it.
2. `DecideskIntegrationService` pre-fills + redirects to Decidesk for e-signature; the `POST /webhooks/decidesk/contract-signed` handler sets `Tenant.contractRef` and completes the contract step.
3. `validateGoLive(tenantId)` checks prerequisites (≥1 zaaktype, ≥1 mandaat, ≥1 tenant_admin), transitions status onboarding → active, sets activatedAt, and triggers quota initialisation (member 09).
4. A nightly reminder for unsigned contracts (14-day) and confirmation emails.

## Impact

- **Affected**: procest (`TenantOnboardingService`, `TenantOnboardingController`, `DecideskIntegrationService`, `WebhookController`, onboarding dashboard Vue), decidesk (contract webflow).
- **Traces to giant tasks**: Task 7 (onboarding checklist + dashboard), Task 8 (Decidesk integration + webhook), Task 9 (go-live validation + activation), REQ-003-A/B/C/D.
- **Depends on**: member 06 (full auth/mandate stack) + member 01 (onboarding template + Tenant schema).
