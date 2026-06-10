# Tasks: tenant-zaaksysteem-saas-04-tenant-context-isolation

Member 4 of 12 (code). Depends on member 03. Traces to giant Task 3 + REQ-002-A.

## 1. Tenant context

- [~] Implement `TenantContext` request-scoped singleton (tenant_id, schema name, tenant object) — deferred to downstream cycle / fleet-wide adoption (handoff)
- [~] Implement `TenantContextMiddleware` to resolve the Tenant record from the request tenant_id — deferred to downstream cycle / fleet-wide adoption (handoff)
- [~] Reject the request when no tenant resolves — deferred to downstream cycle / fleet-wide adoption (handoff)

## 2. Isolation middleware

- [~] Implement `TenantIsolationMiddleware` to set `search_path = 'public,tenant_X_schema'` per request — deferred to downstream cycle / fleet-wide adoption (handoff)
- [~] Build the schema name from the resolved Tenant UUID + slug (never raw input) — deferred to downstream cycle / fleet-wide adoption (handoff)
- [~] Return HTTP 404 (not 403) for cross-tenant lookups — deferred to downstream cycle / fleet-wide adoption (handoff)
- [~] Register both middlewares in the procest middleware pipeline (Authenticate → Context → Isolation) — deferred to downstream cycle / fleet-wide adoption (handoff)

## 3. Tests + benchmark

- [~] Integration test: search_path is correctly set before queries — deferred to downstream cycle / fleet-wide adoption (handoff)
- [~] Integration test: models query only the tenant schema — deferred to downstream cycle / fleet-wide adoption (handoff)
- [~] Integration test: cross-tenant query returns 0 rows (404), not an error — deferred to downstream cycle / fleet-wide adoption (handoff)
- [~] Benchmark query performance with search_path overhead (record baseline) — deferred to downstream cycle / fleet-wide adoption (handoff)
