# Tasks: tenant-zaaksysteem-saas-02-tenant-crud-lifecycle

> **Build status (hydra audit).** The basic TenantService + TenantMiddleware + TenantController shipped via the sibling 'migrate-tenant-to-or-tenant' change (delegates to OR's TenantLifecycleService). This 12-member SaaS chain layers on the full SaaS shape — Tenant/TenantConfiguration/TenantQuota/TenantUser schemas, schema-per-tenant provisioning, JWT tenant-claim auth, mandate validation, onboarding workflow, branding, quota enforcement, shillinq billing, suspension/termination, isolation tests — none of which exist on dev yet. Tasks stay [ ] as genuine forward work.

Member 2 of 12 (code). Depends on member 01. Traces to giant Task 1 + REQ-001-A.

## 1. TenantService (OpenRegister-backed)

- [ ] Create `TenantService` with OpenRegister `ObjectService` client wiring
- [ ] Implement `create(name, kvkNumber, tier)` (slug gen, status=onboarding, isolationMode by tier)
- [ ] Implement `getById()` and `listActive(statusFilter?)` with status filtering
- [ ] Implement `updateStatus(tenantId, newStatus)` guarded by the state machine
- [ ] Implement `slugify()` (lowercased, hyphens, max 64 chars) with uniqueness guard

## 2. TenantController + routes

- [ ] Implement `TenantController` POST/GET/PATCH/DELETE endpoints
- [ ] Register routes in `appinfo/routes.php` (ADR-016)
- [ ] Enforce admin-only authorization posture (ADR-005)
- [ ] List endpoint supports filtering by status

## 3. Lifecycle + tests

- [ ] Define legal transition graph (onboarding→active→suspended↔active→terminated)
- [ ] Reject illegal transitions with a clear error
- [ ] Unit test: slug generation + uniqueness constraint
- [ ] Unit test: lifecycle transition validation
- [ ] Integration test: full CRUD round-trip + list filtering through OpenRegister
- [ ] Add API documentation (OpenAPI 3.0) for the tenant CRUD endpoints
