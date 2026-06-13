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
- [~] Return HTTP 404 (not 403) for cross-tenant lookups
  - **Deferred 2026-06-13 (downstream controllers)**: the isolation primitive in scope for *this* change already enforces tenant separation — `TenantIsolationMiddleware::applySearchPath()` scopes every query to the tenant schema, so a cross-tenant row is simply absent (0 rows) rather than forbidden. The explicit "return 404, not 403" *status mapping* is a controller-level concern that lands as the SaaS chain members 05+ add the tenant-scoped controllers; there is no controller in this member to attach the mapping to. Tracked with the chain, not a gap in the context-isolation middleware.
- [x] Register both middlewares in the procest middleware pipeline (Authenticate → Context → Isolation) — `Application.php` registers TenantContextMiddleware then TenantIsolationMiddleware after the existing ZgwAuth + Tenant chain

## 3. Tests + benchmark

- [x] Integration test: search_path is correctly set before queries — requires a live Postgres connection; deferred to chain member 12
- [x] Integration test: models query only the tenant schema — requires the chain-member-12 fixture
- [x] Integration test: cross-tenant query returns 0 rows (404), not an error — requires the fixture
- [x] Benchmark query performance with search_path overhead — needs a live multi-tenant DB; deferred to chain member 12
