# Design: tenant-zaaksysteem-saas-09-quotas-enforcement

## Scope of this member

Per-tenant quota service, real-time enforcement middleware, and monthly reset job. Emits a `quota_exceeded` billing event consumed by member 10 (the billing pipeline itself is member 10).

## Declarative-first (ADR-031, ADR-001)

`TenantQuota` is a declarative OpenRegister schema (member 01) and the tier limits are seeded templates (member 01). The quota *values* are declarative; the *enforcement* (atomic increment, block at limit, throttle, soft-warn) is imperative runtime glue — `kind: code`. All quota reads/writes go through the `ObjectService`.

## Service layer

### TenantQuotaService
- `initialize(tenantId, tier)` → materialise `TenantQuota` rows from the seeded tier template (basic 100/10/5/1000, standard 1000/100/50/10000, enterprise unlimited); set `resetAt` = first of next month. Invoked from go-live (member 07).
- `getQuota(tenantId, quotaType)`, `checkLimit(tenantId, quotaType, amount)` → `{allowed, remaining}`, `increment(tenantId, quotaType, amount)`.
- `setLimit(...)` for tier upgrades (effect within 1 minute).

### QuotaEnforcementMiddleware
- On case creation → check `cases_per_month`; on API call → check `api_calls_per_hour`.
- enforcement="block" → HTTP 429 + `{error, quota_type, limit, current_usage}` + emit `quota_exceeded` billing event.
- enforcement="throttle" → rate-limit (queue/delay/reject).
- enforcement="warn" → log + allow.
- At ≥ softLimitWarningPercent (80%) → email the tenant admin.

### ResetMonthlyQuotas (OCP\BackgroundJob\Job)
- Daily; selects monthly-reset quotas with `resetAt < today`; sets `currentUsage=0`, `resetAt` = next month 1st. Registered in `appinfo/info.xml`.

## Security (ADR-005)

The increment + check must be atomic to avoid a TOCTOU race that lets two concurrent requests both pass the limit (use an atomic increment-then-compare, or a row lock). Enforcement fails closed: if the quota store is unreachable on a `block` quota, deny rather than allow unmetered usage. Limit PATCH is admin-only.

## Tests

- Unit: tier-based limit initialisation; limits vary by tier.
- Unit: enforcement modes (warn/throttle/block); 429 at limit; 80% soft-warn fires once.
- Integration: atomic increment under concurrent case creation (no over-limit slip).
- Integration: monthly reset job (mock date) resets only quotas with `resetAt < today`; future-dated untouched.
