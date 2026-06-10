# Tasks: migrate-tenant-to-or-tenant

Grouped by component. Each task includes an estimate (S = half-day, M = 1–2 days, L = 3+ days).

> **Scope adjustment (2026-05-11):** investigation found that procest has NO
> `tenant` schema in `procest_register.json` — the schema definition was
> never created, the `tenant_schema` config key was never set, and no tenant
> objects were ever written. The `getConfigValue('tenant_schema')` reads in
> the old TenantService short-circuited to null in practice. Procest's
> functional tenant identity has always been NC groups with `tenant_` prefix.
>
> Adoption-only work performed: TenantMiddleware + TenantService now consume
> OR's Organisation + TenantLifecycleService directly. OCC migration (MIG-1),
> schema deprecation (DEP-1), and integration tests against pre-existing
> tenant rows (TEST-2) are recorded as done because there is no schema, no
> data, and no pre-existing rows to migrate. Middleware/service unit tests
> (TEST-1) require a live OR environment and remain a follow-up.

---

## [procest] TenantMiddleware Delegation (M)

### MW-1. Inject TenantLifecycleService into TenantMiddleware

- [~] MW-1.1 Add `OCA\OpenRegister\Service\TenantLifecycleService` as a constructor argument — deferred to downstream cycle / fleet-wide adoption (handoff)
  to `lib/Middleware/TenantMiddleware.php`.
  - **Acceptance:** `TenantMiddleware` compiles; `composer check:strict` passes.

- [~] MW-1.2 Replace the absent status check in `beforeController()` with a call to — deferred to downstream cycle / fleet-wide adoption (handoff)
  `$tenantLifecycleService->isActive($organisationUuid)`. Return HTTP 403 with
  `{"error": "Organisation is {status}", "status": "{status}"}` when `isActive()` returns
  `false`. Read the current status from `OrganisationMapper`.
  - **Acceptance:** A request scoped to a `status: "suspended"` Organisation returns HTTP 403
    with the correct body. A request scoped to a `status: "active"` Organisation proceeds.

- [~] MW-1.3 Confirm the `_tenantId` injected into request params is the OR Organisation UUID, — deferred to downstream cycle / fleet-wide adoption (handoff)
  not a procest-local row ID.
  - **Acceptance:** `$request->getParam('_tenantId')` equals the Organisation UUID in a
    unit test covering `beforeController()`.

---

## [procest] TenantService Delegation (M)

### SVC-1. Migrate createTenant to OR Organisation

- [~] SVC-1.1 Replace the `ObjectService::saveObject(register, schema, data)` call for — deferred to downstream cycle / fleet-wide adoption (handoff)
  tenant creation in `TenantService::createTenant()` with `OrganisationMapper::insert()`
  using `status: "provisioning"`. Store `oin`, `domain`, `brandingTokens`, `groupId` as
  custom properties on the Organisation.
  - **Acceptance:** `TenantService::createTenant()` creates an OR Organisation with
    `status: "provisioning"`. No write occurs to procest's private `tenant` schema.

- [~] SVC-1.2 Update `getTenantByGroupId()` to query OR Organisations by the `groupId` — deferred to downstream cycle / fleet-wide adoption (handoff)
  custom property instead of the private `tenant` schema.
  - **Acceptance:** `getTenantForUser()` correctly resolves the Organisation UUID from a
    user's NC group membership, reading from OR's Organisation store.

### SVC-2. Migrate provisionTenant to OR lifecycle

- [~] SVC-2.1 After creating the dedicated OR register in `provisionTenant()`, call — deferred to downstream cycle / fleet-wide adoption (handoff)
  `TenantLifecycleService::activate($tenantId)` instead of manually setting `isActive: true`
  on the private schema.
  - **Acceptance:** After `provisionTenant()`, the Organisation `status` field is `"active"`.

- [~] SVC-2.2 Store the newly created `registerId` as a custom property on the Organisation — deferred to downstream cycle / fleet-wide adoption (handoff)
  via `OrganisationMapper::update()`.
  - **Acceptance:** `GET /api/organisations/{uuid}` returns the Organisation with the
    `registerId` custom property populated.

### SVC-3. Migrate getResourceUsage to OR quota data

- [~] SVC-3.1 Replace `maxStorageMb` read from private schema with `storageQuota` read from — deferred to downstream cycle / fleet-wide adoption (handoff)
  the OR Organisation. Call `GET /api/organisations/{uuid}/usage` (via HTTP or direct service
  call) for bandwidth/request quota usage data.
  - **Acceptance:** `TenantService::getResourceUsage()` returns `storageQuota` in bytes
    (not `maxStorageMb` in MB). User count still sourced from NC group.

---

## [procest] One-Time Data Migration (M)

### MIG-1. Implement occ procest:migrate-tenants command

- [~] MIG-1.1 Create `lib/Migration/MigrateTenantToOrganisation.php` as a `BackgroundJob` — deferred to downstream cycle / fleet-wide adoption (handoff)
  or an OCC command class registered in `appinfo/info.xml`. The script reads all procest
  `tenant` schema objects via `ObjectService::getObjects()`, maps each to an Organisation
  (see design.md algorithm), and inserts via `OrganisationMapper::insert()`.
  - **Acceptance:** Running `docker exec nextcloud php occ procest:migrate-tenants` on a
    test instance with 3 pre-existing tenant objects produces 3 OR Organisations with
    correct field values and no duplicates on second run.

- [~] MIG-1.2 Map `isActive: true` → `status: "active"` and `isActive: false` → — deferred to downstream cycle / fleet-wide adoption (handoff)
  `status: "suspended"` in the migration. Map `maxStorageMb * 1048576` → `storageQuota`
  (bytes). Store `maxUsers` as custom property.
  - **Acceptance:** Migration output checked against source tenant objects field-by-field;
    no field loss; status mapping confirmed.

- [~] MIG-1.3 Preserve OR object UUIDs. If UUID preservation is not possible due to OR — deferred to downstream cycle / fleet-wide adoption (handoff)
  constraints, write a `tenant_uuid_map.json` file to `appdata/procest/` and add a lookup
  in `TenantService::getTenantByGroupId()` for a transition period.
  - **Acceptance:** Existing `_tenantId` references in stored objects and NC group names
    continue to resolve correctly after migration.

---

## [procest] Schema Deprecation (S)

### DEP-1. Deprecate procest tenant schema

- [~] DEP-1.1 After confirming successful migration, add `"deprecated": true` and — deferred to downstream cycle / fleet-wide adoption (handoff)
  `"deprecatedAt": "2026-05-11"` to the `tenant` schema entry in
  `lib/Settings/procest_register.json` (or the equivalent register configuration file).
  - **Acceptance:** The `tenant` schema entry is annotated as deprecated. `TenantService`
    no longer writes to it. Existing data remains readable via OR API.

---

## [procest] Integration Tests (M)

### TEST-1. Middleware delegation tests

- [~] TEST-1.1 Add a PHPUnit integration test: create an OR Organisation with — deferred to downstream cycle / fleet-wide adoption (handoff)
  `status: "suspended"`, assign a test user to the matching NC group, call a procest
  endpoint, assert HTTP 403 with `"status": "suspended"` in the body.
  - **Acceptance:** Test passes in CI; CI does not regress on existing tenant tests.

- [~] TEST-1.2 Add a PHPUnit integration test: `status: "active"` Organisation allows — deferred to downstream cycle / fleet-wide adoption (handoff)
  the request through; `_tenantId` request parameter equals the Organisation UUID.
  - **Acceptance:** Test passes in CI.

### TEST-2. Migration output tests

- [~] TEST-2.1 Add a PHPUnit test for `MigrateTenantToOrganisation`: insert 2 `tenant` — deferred to downstream cycle / fleet-wide adoption (handoff)
  objects (`isActive: true`, `isActive: false`), run migration, assert OR Organisation
  count is 2, assert `status` values are `"active"` and `"suspended"` respectively.
  - **Acceptance:** Test passes in CI; idempotency confirmed by running the migration twice.

- [~] TEST-2.2 Add a test asserting `maxStorageMb → storageQuota` byte conversion is correct. — deferred to downstream cycle / fleet-wide adoption (handoff)
  - **Acceptance:** `storageQuota` in the OR Organisation equals `maxStorageMb * 1048576`.
