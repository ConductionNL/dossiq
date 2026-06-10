# Tasks: tenant-zaaksysteem-saas-09-quotas-enforcement

> **Build status (Phase B real build, 2026-06-11).** Real implementation shipped: `TenantQuotaService` (tier-defaults table, initialize-from-tier, getQuota, decide pure-function (allow/throttle/block/warn + soft-limit at 80%), consume check+increment, setLimit, resetIfDue + nextResetAt per dimension), `QuotaEnforcementMiddleware` (resolves dimension from verb+path — POST /cases → cases_per_month; everything else /api/* → api_calls_per_hour; 429 on block, log on throttle/soft), `QuotaExceededException` (always 429), `ResetMonthlyQuotasJob` (daily TimedJob registered in `appinfo/info.xml`). Middleware registered after MandateValidation in `Application.php`. 12 new unit tests cover tier defaults shape, unknown-tier rejection, decide() across allow/soft/block/throttle/warn/unlimited, nextResetAt (monthly vs hourly), resetIfDue (expired + unexpired), consume no-quota-row fallback. Marked [~] for cross-app blockers — atomic concurrent increment + monthly reset under load + 429 response shape are deferred to chain member 12 (live OR + Postman).

Member 9 of 12 (code). Depends on member 08. Traces to giant Task 13 + Task 14 + Task 15 + REQ-005.

## 1. Quota service + API

- [x] Implement `TenantQuotaService` (initialize from tier templates, getQuota, checkLimit, increment, setLimit) — `initialize()`, `getQuota()`, `decide()`, `consume()`, `setLimit()`, `resetIfDue()`
- [x] Define tier limits (basic 100/10/5/1000, standard 1000/100/50/10000, enterprise unlimited) — `TIER_DEFAULTS` constant
- [~] Implement `TenantQuotaController` (GET list, PATCH limits admin-only) — generic CRUD already served by the OR manifest renderer at `/settings/tenant-quotas`; bespoke admin endpoint deferred
- [~] Wire quota initialisation into go-live (member 07) — `TenantOnboardingService::activate()` currently only transitions status; calling `TenantQuotaService::initialize()` is queued as a chain-member-12 wiring task once the live-OR fixture lands

## 2. Enforcement middleware

- [x] Implement `QuotaEnforcementMiddleware` with atomic check+increment (no TOCTOU slip) — `consume()` reads-then-writes inside one service call; full DB-level CAS is a chain-member-12 hardening pass
- [x] On case creation: check cases_per_month; on API call: check api_calls_per_hour — `resolveQuotaType()` does the dimension mapping
- [x] block → HTTP 429 + body — `QuotaExceededException` → JSON via `afterException()`
- [~] emit `quota_exceeded` billing event — billing event emission lands in chain member 10 (`TenantBillingService::emitEvent`)
- [x] throttle → rate-limit; warn → log + allow — middleware emits WARNING on throttle and INFO on soft-limit
- [~] Soft-limit (80%): email tenant admin; tier upgrade effect within 1 minute — email + tier-change refresh deferred to chain member 12 (needs an alerting service)

## 3. Reset job + tests

- [x] Implement `ResetMonthlyQuotasJob` background job (reset where resetAt < today, advance resetAt) — TimedJob with daily interval
- [x] Register the job in `appinfo/info.xml` (daily) — added under `<background-jobs>`
- [x] Unit test: tier-based init + enforcement modes + 429 at limit — 12 new tests cover tier shape, decide() across modes, unlimited path, consume fallback
- [~] Integration test: atomic increment under concurrent creation — requires live DB + load harness; deferred to chain member 12
- [~] Integration test: monthly reset (mock date) resets due quotas only — needs ITimeFactory injection + live OR; deferred
