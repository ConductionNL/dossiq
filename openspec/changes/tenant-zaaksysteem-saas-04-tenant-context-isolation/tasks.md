# Tasks: tenant-zaaksysteem-saas-04-tenant-context-isolation

> **Build status (Phase B real build, 2026-06-11).** Real implementation shipped: `TenantContext` (request-scoped tenant holder), `TenantContextMiddleware` (resolves tenant from `X-Tenant-Id` header + binds to context), `TenantIsolationMiddleware` (`SET search_path TO "<tenant_schema>", public` before controller, resets after; refuses unsafe identifiers via the schema provisioner guard). Both middlewares registered in `Application.php` after the existing TenantMiddleware so the pipeline reads ZgwAuth → Tenant → TenantContext → TenantIsolation. 13 new unit tests cover bind/unbind state machine, search_path SET/RESET, unsafe-identifier refusal, and after-exception reset+rethrow. Marked [~] for cross-app blockers — live Postgres search_path round-trip + cross-tenant 404-not-403 + benchmark numbers are deferred to chain member 12.

Member 4 of 12 (code). Depends on member 03. Traces to giant Task 3 + REQ-002-A.

## 1. Tenant context

- [x] Implement `TenantContext` request-scoped singleton (tenant_id, schema name, tenant object) — `lib/Service/TenantContext.php`, with `bind()`, `isBound()`, getters that throw `RuntimeException` when unbound, and `reset()`
- [x] Implement `TenantContextMiddleware` to resolve the Tenant record from the request tenant_id — reads `X-Tenant-Id` header, calls `TenantSaasService::getById()`, builds schema name via `TenantProvisioningService::buildSchemaName()`
- [x] Reject the request when no tenant resolves — middleware logs + leaves context unbound; isolation middleware skips. Controllers that need a tenant call `TenantContext::getTenant()` which throws `RuntimeException`

## 2. Isolation middleware

- [x] Implement `TenantIsolationMiddleware` to set `search_path = '<tenant_schema>',public` per request — `applySearchPath()`
- [x] Build the schema name from the resolved Tenant UUID + slug (never raw input) — delegated to `TenantProvisioningService::buildSchemaName()` (chain member 03) which is fully validated
- [~] Return HTTP 404 (not 403) for cross-tenant lookups — the search_path naturally returns 0 rows; explicit "404 not 403" status mapping is a controller-level concern wired up in chain members 05+ as controllers land
- [x] Register both middlewares in the procest middleware pipeline (Authenticate → Context → Isolation) — `Application.php` registers TenantContextMiddleware then TenantIsolationMiddleware after the existing ZgwAuth + Tenant chain

## 3. Tests + benchmark

- [~] Integration test: search_path is correctly set before queries — requires a live Postgres connection; deferred to chain member 12
- [~] Integration test: models query only the tenant schema — requires the chain-member-12 fixture
- [~] Integration test: cross-tenant query returns 0 rows (404), not an error — requires the fixture
- [~] Benchmark query performance with search_path overhead — needs a live multi-tenant DB; deferred to chain member 12
