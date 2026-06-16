---
status: done
note: Implemented and archived 2026-06-13 (change tenant-zaaksysteem-saas-04). TenantContext + TenantContextMiddleware + TenantIsolationMiddleware shipped and registered. The explicit 404-vs-403 status mapping is deferred ([~]) to downstream tenant-scoped controllers (SaaS chain members 05+); query-level isolation via search_path is in place.
---

# tenant-isolation Specification

## Purpose
Provides request-scoped tenant isolation for the multi-tenant SaaS deployment: a `TenantContext` singleton resolved from the `X-Tenant-Id` header, and middleware that sets the PostgreSQL `search_path` to the tenant's schema so every query is scoped to a single tenant regardless of injected filters.
## Requirements
### Requirement: Query-level isolation via search_path (REQ-002-A)

The system SHALL set the PostgreSQL `search_path` per request so each tenant's queries reach only that tenant's schema, even under a maliciously injected filter.

#### Scenario: search_path set before queries

- **GIVEN** User-A (tenant_id=A) and User-B (tenant_id=B) query the case table simultaneously
- **WHEN** User-A's request is processed
- **THEN** `TenantIsolationMiddleware` SHALL set `search_path = 'public,tenant_A_schema'` before any query
- **AND** User-A SHALL see only cases residing in tenant_A_schema
- **AND** User-B's parallel request SHALL have `search_path = 'public,tenant_B_schema'` and see only tenant_B cases

#### Scenario: Injected cross-tenant filter cannot reach other data

- **GIVEN** User-A's search_path is `public,tenant_A_schema`
- **WHEN** User-A submits a malicious filter `WHERE tenant_id='B'`
- **THEN** the query SHALL still resolve only within tenant_A_schema (the other tenant's table is not on the search_path)
- **AND** no tenant B rows SHALL be returned

### Requirement: Request-scoped tenant context (REQ-002-A-CONTEXT)

The system SHALL maintain a request-scoped tenant context resolved from the request's tenant_id, and SHALL return HTTP 404 (not 403) for cross-tenant resource lookups to avoid leaking tenant existence.

#### Scenario: Cross-tenant lookup returns 404

- **GIVEN** a request carries tenant_id=A and path `/api/cases/case-xyz` where case-xyz exists only in tenant B's schema
- **WHEN** the request is processed with search_path for tenant A
- **THEN** the query SHALL return 0 rows
- **AND** the application SHALL respond HTTP 404 (not found), not HTTP 403, to avoid leaking tenant existence

#### Scenario: search_path overhead is benchmarked

- **GIVEN** the isolation middleware is active
- **WHEN** the query-performance benchmark runs
- **THEN** the per-request `SET search_path` overhead SHALL be measured and recorded as a baseline for later performance auditing

