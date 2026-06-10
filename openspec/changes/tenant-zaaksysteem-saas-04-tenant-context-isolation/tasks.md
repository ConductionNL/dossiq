# Tasks: tenant-zaaksysteem-saas-04-tenant-context-isolation

Member 4 of 12 (code). Depends on member 03. Traces to giant Task 3 + REQ-002-A.

## 1. Tenant context

- [ ] Implement `TenantContext` request-scoped singleton (tenant_id, schema name, tenant object)
- [ ] Implement `TenantContextMiddleware` to resolve the Tenant record from the request tenant_id
- [ ] Reject the request when no tenant resolves

## 2. Isolation middleware

- [ ] Implement `TenantIsolationMiddleware` to set `search_path = 'public,tenant_X_schema'` per request
- [ ] Build the schema name from the resolved Tenant UUID + slug (never raw input)
- [ ] Return HTTP 404 (not 403) for cross-tenant lookups
- [ ] Register both middlewares in the procest middleware pipeline (Authenticate → Context → Isolation)

## 3. Tests + benchmark

- [ ] Integration test: search_path is correctly set before queries
- [ ] Integration test: models query only the tenant schema
- [ ] Integration test: cross-tenant query returns 0 rows (404), not an error
- [ ] Benchmark query performance with search_path overhead (record baseline)
