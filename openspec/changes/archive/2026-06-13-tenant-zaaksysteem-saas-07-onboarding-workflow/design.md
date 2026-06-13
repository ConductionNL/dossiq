# Design: tenant-zaaksysteem-saas-07-onboarding-workflow

## Scope of this member

Guided onboarding: checklist + progress dashboard, Decidesk contract integration, go-live validation/activation. Quota initialisation is invoked here but implemented in member 09; branding/SSO setup steps link to member 08.

## Declarative-first (ADR-031, ADR-001)

`TenantOnboardingTask` is a declarative OpenRegister schema (member 01); this member's `createOnboarding` forks the seeded default-tenant template into per-tenant task rows via the `ObjectService`. The workflow orchestration (state transitions, webhook handling, go-live checks) is imperative — `kind: code`.

## Service layer

### TenantOnboardingService
- `createOnboarding(tenantId)` → fork the 7-step template (contract, mandate_import, sso_setup, branding, zaaktype_selection, first_user, go_live), all `pending`; email the admin a checklist link.
- `getProgress(tenantId)` → steps with status + helper links.
- `markStepComplete(tenantId, step)` → validate prerequisites, mark done with timestamp + completedBy.
- `validateGoLive(tenantId)` → require ≥1 non-draft zaaktype, ≥1 mandaat (skippable for local-auth only), ≥1 tenant_admin; on pass transition status → active, set activatedAt, trigger quota init (member 09).

### DecideskIntegrationService
- `initiateContractSignature(tenantId)` → redirect to Decidesk with pre-filled tenant details.
- `handleContractSigned(tenantId, decidesk_contract_id)` → set `Tenant.contractRef`, complete the contract step, notify admin.

## API routes (ADR-016)

```
GET  /api/tenants/{tenantId}/onboarding                  → progress()
POST /api/tenants/{tenantId}/onboarding/{step}/complete  → markStepComplete()
POST /api/tenants/{tenantId}/onboarding/go-live          → goLive()
POST /webhooks/decidesk/contract-signed                  → WebhookController (Decidesk)
```

## Frontend (ADR-004)

Onboarding progress dashboard Vue component: progress bar X/7, per-step status badge, next-step CTA, contextual help sidebar. All text via `t('procest', ...)` (nl + en, ADR-007). Any modal lives in its own file (modal-isolation gate); NcSelect carries `inputLabel`.

## Security (ADR-005)

The Decidesk webhook endpoint is the one public surface here — it MUST verify the webhook signature/shared secret before trusting the payload (no unauthenticated contractRef write); it is `#[PublicPage]` + signature-verified, not unauthenticated-open. Onboarding step endpoints are tenant-admin scoped via the mandate stack (member 06). Decidesk errors (timeout, rejection) are handled gracefully, not swallowed.

## Tests

- Integration: checklist initialises with 7 pending steps; dashboard reflects status; admin email sent.
- Integration: Decidesk webhook sets contractRef + completes the contract step (signature verified).
- Integration: go-live validation lists missing prerequisites; on pass transitions to active + sets activatedAt.
