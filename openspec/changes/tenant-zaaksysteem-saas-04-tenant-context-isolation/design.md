# Design: tenant-zaaksysteem-saas-04-tenant-context-isolation

## Scope of this member

Request-scoped tenant context + the two middlewares that resolve a tenant and set the PostgreSQL `search_path`. This is the imperative access-layer enforcement of the declarative model; JWT extraction is member 05, mandate checks are member 06.

## Declarative-first (ADR-031, ADR-001)

Isolation is request-time infrastructure (DB connection state) and has no declarative analogue — `kind: code` per ADR-032. The middleware does not change the OpenRegister object shapes; it scopes which physical schema the `ObjectService` connection reads. ADR-022 (apps consume OpenRegister abstractions) holds: the search_path is set on the shared connection, so all `ObjectService` calls inherit tenant scope without per-query changes.

## Components

### TenantContext (request-scoped singleton)
Holds `tenantId`, `schemaName`, and the resolved `Tenant` object for the lifetime of one request. Populated by `TenantContextMiddleware`, read by `TenantIsolationMiddleware` and every downstream consumer.

### TenantContextMiddleware
Reads the tenant_id provided by the auth layer (member 05 populates it from the JWT; this member treats it as an input), resolves the `Tenant` record via OpenRegister, and stores it in `TenantContext`. If no tenant resolves, the request is rejected.

### TenantIsolationMiddleware
Before any query runs, executes `SET search_path = 'public,tenant_{uuid}_{slug}'` on the request connection. Because the application tables live only inside the tenant schema, a malicious `WHERE tenant_id='B'` cannot reach tenant B's data — the table simply is not on the search_path. Cross-tenant access resolves to 0 rows → HTTP 404 (not 403), avoiding tenant-existence disclosure.

## Middleware registration

Registered in the procest middleware pipeline ordering: Authenticate → TenantContext → TenantIsolation → (Quota, Mandate from later members). Registration follows the app's `Application.php` / middleware kernel pattern.

## Security (ADR-005)

This is the primary data-isolation control. The schema name written into `SET search_path` is built from the resolved `Tenant` UUID + stored slug — never from raw request input — so no SQL injection into the `SET` statement. The 404-not-403 choice is deliberate (REQ-002-B) to avoid leaking which tenant slugs exist. Cross-tenant attempts are surfaced for the audit logging added in member 12 / REQ-002-C.

## Performance

Benchmark query latency with the per-request `SET search_path` overhead; the target is the REQ p99 budget (< 200ms for case operations). Record the baseline so member 12's performance audit can compare.

## Tests

- Integration: search_path is set before queries; models query only the tenant schema.
- Integration: cross-tenant query returns 0 rows (404), not an error.
- Benchmark: query performance with search_path overhead.
