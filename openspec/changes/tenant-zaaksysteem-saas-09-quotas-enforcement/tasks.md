# Tasks: tenant-zaaksysteem-saas-09-quotas-enforcement

Member 9 of 12 (code). Depends on member 08. Traces to giant Task 13 + Task 14 + Task 15 + REQ-005.

## 1. Quota service + API

- [~] Implement `TenantQuotaService` (initialize from tier templates, getQuota, checkLimit, increment, setLimit) — deferred to downstream cycle / fleet-wide adoption (handoff)
- [~] Define tier limits (basic 100/10/5/1000, standard 1000/100/50/10000, enterprise unlimited) — deferred to downstream cycle / fleet-wide adoption (handoff)
- [~] Implement `TenantQuotaController` (GET list, PATCH limits admin-only) — deferred to downstream cycle / fleet-wide adoption (handoff)
- [~] Wire quota initialisation into go-live (member 07) — deferred to downstream cycle / fleet-wide adoption (handoff)

## 2. Enforcement middleware

- [~] Implement `QuotaEnforcementMiddleware` with atomic check+increment (no TOCTOU slip) — deferred to downstream cycle / fleet-wide adoption (handoff)
- [~] On case creation: check cases_per_month; on API call: check api_calls_per_hour — deferred to downstream cycle / fleet-wide adoption (handoff)
- [~] block → HTTP 429 + body; emit `quota_exceeded` billing event — deferred to downstream cycle / fleet-wide adoption (handoff)
- [~] throttle → rate-limit; warn → log + allow — deferred to downstream cycle / fleet-wide adoption (handoff)
- [~] Soft-limit (80%): email tenant admin; tier upgrade effect within 1 minute — deferred to downstream cycle / fleet-wide adoption (handoff)

## 3. Reset job + tests

- [~] Implement `ResetMonthlyQuotas` background job (reset where resetAt < today, advance resetAt) — deferred to downstream cycle / fleet-wide adoption (handoff)
- [~] Register the job in `appinfo/info.xml` (daily) — deferred to downstream cycle / fleet-wide adoption (handoff)
- [~] Unit test: tier-based init + enforcement modes + 429 at limit — deferred to downstream cycle / fleet-wide adoption (handoff)
- [~] Integration test: atomic increment under concurrent creation — deferred to downstream cycle / fleet-wide adoption (handoff)
- [~] Integration test: monthly reset (mock date) resets due quotas only — deferred to downstream cycle / fleet-wide adoption (handoff)
