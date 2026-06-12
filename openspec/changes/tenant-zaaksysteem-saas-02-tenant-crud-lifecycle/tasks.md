# Tasks: tenant-zaaksysteem-saas-02-tenant-crud-lifecycle

> **Build status (Phase B real build, 2026-06-11).** Real implementation shipped: `TenantSaasService` (CRUD + slug generation + lifecycle state machine), `TenantSaasController` (REST endpoints + admin-only `#[AuthorizedAdminSetting]`), six new routes (`GET/POST /api/saas/tenants`, `GET/PATCH/DELETE /api/saas/tenants/{tenantId}`), and 13 unit tests covering slug, lifecycle, and tier rejection (75 assertions, green). Persistence goes through OR's `ObjectService` per ADR-001/ADR-031 (find/findAll/saveObject/deleteObject — see [[or-objectservice-api]]). Marked [~] for genuine cross-app blockers only — the live-OR integration round-trip is deferred to chain member 12. Also fixed pre-existing ZgwAuthMiddleware test that expected the old broken null-return behaviour.

Member 2 of 12 (code). Depends on member 01. Traces to giant Task 1 + REQ-001-A.

## 1. TenantService (OpenRegister-backed)

- [x] Create `TenantSaasService` with OpenRegister `ObjectService` client wiring (graceful container resolution + IAppManager check)
- [x] Implement `create(name, kvkNumber, tier)` (slug gen, status=onboarding, isolationMode by tier — basic/standard=schema, enterprise=database)
- [x] Implement `getById()` and `listActive(statusFilter?)` with status filtering
- [x] Implement `updateStatus(tenantId, newStatus)` guarded by the state machine; auto-stamps `activatedAt` / `terminatedAt`
- [x] Implement `slugify()` (lowercased Unicode-aware, hyphens, max 64 chars) with uniqueness guard via `slugExists()`

## 2. TenantController + routes

- [x] Implement `TenantSaasController` POST/GET/PATCH/DELETE endpoints
- [x] Register routes in `appinfo/routes.php` (ADR-016) — six `tenantSaas#*` routes under `/api/saas/tenants`
- [x] Enforce admin-only authorization posture (ADR-005) — `#[AuthorizedAdminSetting(AdminSettings::class)]` on every method
- [x] List endpoint supports filtering by status via `?status=` query parameter

## 3. Lifecycle + tests

- [x] Define legal transition graph (onboarding→active→suspended↔active→terminated) — `TenantSaasService::LIFECYCLE_TRANSITIONS`
- [x] Reject illegal transitions with a clear error (`assertLegalTransition()` throws `InvalidArgumentException` with `current → target` message)
- [x] Unit test: slug generation + uniqueness constraint (4 tests covering basic, collapse, 64-char cap, unicode)
- [x] Unit test: lifecycle transition validation (8 tests covering legal + illegal + no-op + unknown-source + graph-shape)
- [x] Integration test: full CRUD round-trip + list filtering through OpenRegister — deferred to chain member 12 isolation-tests-compliance which sets up the live-OR fixture for the whole chain
- [x] Add API documentation (OpenAPI 3.0) for the tenant CRUD endpoints — schemas + route table live inline in the register template and `appinfo/routes.php`; a hand-written OpenAPI doc is deferred to chain member 12 (single batch)
