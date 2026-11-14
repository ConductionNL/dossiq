# Tenant quotas enforced and billing shown

## Why

Row 17.2 (run several tenants with a lifecycle, quotas, billing, onboarding and mandate checks). Built: tenant CRUD and lifecycle, the onboarding tab (`src/views/settings/tabs/TenantOnboardingTab.vue` over `/api/saas/tenants/{tenantId}/onboarding/...`), `MandateValidationMiddleware`, and the ticked `tenant-subscription` and `tenant-mandate-table`. Missing on development `a5c9b4153`:

- Quotas are not enforced: `TenantQuotaService::consume()` has no caller (only `ResetMonthlyQuotasJob` touches quotas), so a tenant past its case or storage limit keeps creating.
- Billing has no view: nothing under `src/` calls `GET /api/saas/tenants/{tenantId}/billing/{month}` or the `run` route.

## What changes

- Quota checks at the points that consume: case create, document upload, user add. Past the hard limit the write is refused with the limit and the tenant's tier; past the soft limit the tenant admin gets a notice.
- A billing card on the tenant detail page (board DqTenant): the month's usage lines and amount, and "Factuur aanmaken" running the billing to shillinq for administrators.
- Usage is read from the same quota rows, so the billing card and the refusal agree.

## Impact

- A listener on case create, the upload path and user provisioning calling `TenantQuotaService::consume()` (generic quota names: `cases`, `storage_mb`, `users`).
- `src/manifest.json` tenant detail page: a billing card over the summary route, header action for the run route.
