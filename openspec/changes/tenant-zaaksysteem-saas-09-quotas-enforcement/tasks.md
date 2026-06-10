# Tasks: tenant-zaaksysteem-saas-09-quotas-enforcement

> **Build status (hydra audit).** The basic TenantService + TenantMiddleware + TenantController shipped via the sibling 'migrate-tenant-to-or-tenant' change (delegates to OR's TenantLifecycleService). This 12-member SaaS chain layers on the full SaaS shape — Tenant/TenantConfiguration/TenantQuota/TenantUser schemas, schema-per-tenant provisioning, JWT tenant-claim auth, mandate validation, onboarding workflow, branding, quota enforcement, shillinq billing, suspension/termination, isolation tests — none of which exist on dev yet. Tasks stay [ ] as genuine forward work.

Member 9 of 12 (code). Depends on member 08. Traces to giant Task 13 + Task 14 + Task 15 + REQ-005.

## 1. Quota service + API

- [ ] Implement `TenantQuotaService` (initialize from tier templates, getQuota, checkLimit, increment, setLimit)
- [ ] Define tier limits (basic 100/10/5/1000, standard 1000/100/50/10000, enterprise unlimited)
- [ ] Implement `TenantQuotaController` (GET list, PATCH limits admin-only)
- [ ] Wire quota initialisation into go-live (member 07)

## 2. Enforcement middleware

- [ ] Implement `QuotaEnforcementMiddleware` with atomic check+increment (no TOCTOU slip)
- [ ] On case creation: check cases_per_month; on API call: check api_calls_per_hour
- [ ] block → HTTP 429 + body; emit `quota_exceeded` billing event
- [ ] throttle → rate-limit; warn → log + allow
- [ ] Soft-limit (80%): email tenant admin; tier upgrade effect within 1 minute

## 3. Reset job + tests

- [ ] Implement `ResetMonthlyQuotas` background job (reset where resetAt < today, advance resetAt)
- [ ] Register the job in `appinfo/info.xml` (daily)
- [ ] Unit test: tier-based init + enforcement modes + 429 at limit
- [ ] Integration test: atomic increment under concurrent creation
- [ ] Integration test: monthly reset (mock date) resets due quotas only
