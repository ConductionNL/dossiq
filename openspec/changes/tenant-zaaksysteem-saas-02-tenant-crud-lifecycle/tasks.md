# Tasks: tenant-zaaksysteem-saas-02-tenant-crud-lifecycle

Member 2 of 12 (code). Depends on member 01. Traces to giant Task 1 + REQ-001-A.

## 1. TenantService (OpenRegister-backed)

- [~] Create `TenantService` with OpenRegister `ObjectService` client wiring — deferred to downstream cycle / fleet-wide adoption (handoff)
- [~] Implement `create(name, kvkNumber, tier)` (slug gen, status=onboarding, isolationMode by tier) — deferred to downstream cycle / fleet-wide adoption (handoff)
- [~] Implement `getById()` and `listActive(statusFilter?)` with status filtering — deferred to downstream cycle / fleet-wide adoption (handoff)
- [~] Implement `updateStatus(tenantId, newStatus)` guarded by the state machine — deferred to downstream cycle / fleet-wide adoption (handoff)
- [~] Implement `slugify()` (lowercased, hyphens, max 64 chars) with uniqueness guard — deferred to downstream cycle / fleet-wide adoption (handoff)

## 2. TenantController + routes

- [~] Implement `TenantController` POST/GET/PATCH/DELETE endpoints — deferred to downstream cycle / fleet-wide adoption (handoff)
- [~] Register routes in `appinfo/routes.php` (ADR-016) — deferred to downstream cycle / fleet-wide adoption (handoff)
- [~] Enforce admin-only authorization posture (ADR-005) — deferred to downstream cycle / fleet-wide adoption (handoff)
- [~] List endpoint supports filtering by status — deferred to downstream cycle / fleet-wide adoption (handoff)

## 3. Lifecycle + tests

- [~] Define legal transition graph (onboarding→active→suspended↔active→terminated) — deferred to downstream cycle / fleet-wide adoption (handoff)
- [~] Reject illegal transitions with a clear error — deferred to downstream cycle / fleet-wide adoption (handoff)
- [~] Unit test: slug generation + uniqueness constraint — deferred to downstream cycle / fleet-wide adoption (handoff)
- [~] Unit test: lifecycle transition validation — deferred to downstream cycle / fleet-wide adoption (handoff)
- [~] Integration test: full CRUD round-trip + list filtering through OpenRegister — deferred to downstream cycle / fleet-wide adoption (handoff)
- [~] Add API documentation (OpenAPI 3.0) for the tenant CRUD endpoints — deferred to downstream cycle / fleet-wide adoption (handoff)
