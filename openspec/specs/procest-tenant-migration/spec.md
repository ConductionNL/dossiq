# procest-tenant-migration Specification

## Purpose

Migrate procest's parallel `TenantService`, `TenantMiddleware`, and `TenantController` to
consume OpenRegister's Organisation entity + tenant-lifecycle API, preserving all existing
API endpoints and response shapes, plus a one-time idempotent data migration
(`occ procest:migrate-tenants`) from procest's deprecated private `tenant` schema to OR
Organisations. References `consume-or-tenant-fleet-wide` as the authoritative fleet policy.

@e2e exclude Backend tenant-infrastructure migration with no UI surface — middleware 403/context-injection, service delegation, the `procest:migrate-tenants` OCC command, and schema deprecation are covered by PHPUnit (`tests/Unit/Service/TenantMigrationServiceTest.php`) and the live-NC integration round-trip (deferred TEST-1), not Playwright.

## Requirements
### Requirement: TenantMiddleware MUST Delegate Status Check to OR

procest's `TenantMiddleware::beforeController()` MUST check tenant status by calling OR's
`TenantLifecycleService::isActive()`. It MUST NOT read procest's private `tenant` schema's
`isActive` boolean to make access control decisions.

#### Scenario: Middleware blocks request for suspended OR Organisation

- GIVEN OR has an Organisation with UUID `org-123` and `status: "suspended"`
- AND the current user belongs to the `tenant_gemeente-utrecht` NC group
- AND procest's TenantMiddleware resolves this group to OR Organisation `org-123`
- WHEN an authenticated procest API request arrives
- THEN `TenantMiddleware` MUST call OR's `TenantLifecycleService::isActive('org-123')`
- AND `isActive()` MUST return `false`
- AND the middleware MUST return HTTP 403 with body `{"error": "Organisation is suspended", "status": "suspended"}`

#### Scenario: Middleware allows request for active OR Organisation

- GIVEN OR has an Organisation with UUID `org-456` and `status: "active"`
- AND the current user's NC group resolves to `org-456`
- WHEN an authenticated procest API request arrives
- THEN `TenantMiddleware` MUST call `isActive('org-456')`, which returns `true`
- AND the middleware MUST NOT return HTTP 403
- AND `_tenantId` MUST be set to `org-456` in the request parameters

#### Scenario: Middleware injects OR Organisation UUID as tenant context

- GIVEN a resolved active OR Organisation with UUID `org-456` and `registerId` custom property `reg-789`
- WHEN `TenantMiddleware::beforeController()` completes
- THEN `$request->getParam('_tenantId')` MUST equal `org-456`
- AND `$request->getParam('_tenantRegisterId')` MUST equal `reg-789`
- AND `$request->getParam('_tenantSlug')` MUST equal the Organisation's `slug` field

---

### Requirement: TenantService MUST Delegate Create and Provision to OR

`TenantService::createTenant()` MUST create an OR Organisation object with `status: "provisioning"`.
`TenantService::provisionTenant()` MUST call OR's `TenantLifecycleService::activate()` after
resource creation to transition the Organisation to `status: "active"`.

#### Scenario: createTenant creates an OR Organisation in provisioning state

- GIVEN a call to `TenantService::createTenant('Gemeente Utrecht', '00000001001234567000', null)`
- WHEN the method executes
- THEN `OrganisationMapper::insert()` MUST be called with `status: "provisioning"`
- AND the Organisation MUST have `name: "Gemeente Utrecht"` and `slug: "gemeente-utrecht"`
- AND the custom property `oin` MUST equal `"00000001001234567000"`
- AND the Nextcloud group `tenant_gemeente-utrecht` MUST be created
- AND the returned array MUST include the OR Organisation UUID

#### Scenario: provisionTenant activates the OR Organisation after resource creation

- GIVEN an OR Organisation `org-123` with `status: "provisioning"`
- WHEN `TenantService::provisionTenant('org-123')` is called
- THEN a dedicated OR register MUST be created via `RegisterService::createFromArray()`
- AND the register UUID MUST be stored as the `registerId` custom property on `org-123`
- AND `TenantLifecycleService::activate('org-123')` MUST be called
- AND the Organisation's `status` MUST transition to `"active"` in OR

#### Scenario: getResourceUsage reads quota from OR Organisation

- GIVEN OR Organisation `org-123` has `storageQuota: 1073741824` (1 GB)
- WHEN `TenantService::getResourceUsage('org-123')` is called
- THEN the returned array MUST include `storageQuota: 1073741824`
- AND the returned `users` count MUST reflect the current members of the `tenant_gemeente-utrecht` NC group
- AND procest MUST NOT read a `maxStorageMb` field from a private tenant schema

---

### Requirement: TenantController API Surface MUST Be Preserved

All procest tenant API endpoints MUST remain available with identical HTTP paths, methods,
and response shapes after the migration. No client-facing API changes are permitted.

#### Scenario: POST /api/tenants creates a tenant via OR

- GIVEN an authenticated platform admin sends `POST /api/tenants` with `{"name": "Gemeente Utrecht", "oin": "000...", "domain": null}`
- WHEN the controller calls `TenantService::createTenant()`
- THEN the response MUST be `{"success": true, "tenant": {...}}` with the OR Organisation data
- AND the HTTP status MUST be 200

#### Scenario: POST /api/tenants/{id}/provision activates an OR Organisation

- GIVEN an OR Organisation `org-123` with `status: "provisioning"`
- WHEN an admin sends `POST /api/tenants/org-123/provision`
- THEN the response MUST be `{"success": true, "tenant": {...}}` with `status: "active"` in the tenant object
- AND the response MUST have HTTP status 200

#### Scenario: GET /api/tenants/current returns the user's OR Organisation

- GIVEN the current user belongs to NC group `tenant_gemeente-utrecht` mapping to `org-456`
- WHEN `GET /api/tenants/current` is called
- THEN the response MUST include the OR Organisation `org-456` serialized in the `tenant` key
- AND the response MUST include the five-state `status` field (not a boolean `isActive`)

---

### Requirement: One-Time Data Migration MUST Preserve Tenant Records

A one-time data migration script MUST transfer all existing procest `tenant` schema objects
to OR Organisation entities. The migration MUST be idempotent and MUST preserve object UUIDs
where possible.

#### Scenario: Migration creates OR Organisation for each procest tenant

- GIVEN procest has 3 existing tenant objects in its `tenant` OR schema
- WHEN the migration script `occ procest:migrate-tenants` runs
- THEN 3 OR Organisation objects MUST exist after the run
- AND each Organisation MUST have the same UUID as the corresponding procest tenant object
- AND `name`, `slug`, `groupId`, `oin`, `domain`, `brandingTokens`, `registerId` MUST be preserved as fields or custom properties

#### Scenario: Migration maps isActive boolean to OR lifecycle status

- GIVEN procest tenant object `T1` has `isActive: true` and tenant object `T2` has `isActive: false`
- WHEN the migration runs
- THEN OR Organisation for `T1` MUST have `status: "active"`
- AND OR Organisation for `T2` MUST have `status: "suspended"`

#### Scenario: Migration is idempotent

- GIVEN the migration script has already run and 3 Organisations exist
- WHEN the script is run a second time
- THEN no duplicate Organisation objects MUST be created
- AND the script MUST log a summary indicating N tenants already existed

#### Scenario: Migration preserves maxUsers as custom property

- GIVEN procest tenant object `T1` has `maxUsers: 50`
- WHEN the migration runs
- THEN OR Organisation for `T1` MUST have a custom property `maxUsers: 50`
- AND OR's `storageQuota` field MUST be set to `maxStorageMb * 1048576` (bytes conversion)

---

### Requirement: Procest Tenant Schema MUST Be Deprecated After Migration

procest's `tenant` schema entry in `procest_register.json` MUST be marked deprecated after
the one-time migration completes successfully. No new tenant objects SHALL be written to the
deprecated schema.

#### Scenario: Deprecated schema annotated in register JSON

- GIVEN the migration has completed with no errors
- WHEN `procest_register.json` is updated
- THEN the `tenant` schema entry MUST include `"deprecated": true` and `"deprecatedAt": "2026-05-11"`
- AND any attempt to call `TenantService::createTenant()` post-migration MUST write to OR's Organisation, not to the deprecated schema

#### Scenario: Deprecated schema data remains readable

- GIVEN procest's `tenant` schema is marked deprecated after migration
- WHEN an administrator queries pre-migration tenant objects via the OR API
- THEN existing `tenant` objects MUST remain readable via `GET /api/objects/{register}/{schema}`
- AND no new objects SHALL be created in the deprecated schema

---

### Requirement: Integration Tests MUST Verify OR Lifecycle Delegation

procest MUST include integration tests that confirm TenantMiddleware correctly delegates to
OR's tenant-lifecycle service and that the one-time migration produces correct Organisation
objects.

#### Scenario: Test confirms suspended Organisation returns 403

- GIVEN a test that creates an OR Organisation with `status: "suspended"` and assigns a user to the matching NC group
- WHEN the test calls a procest API endpoint as that user
- THEN the response MUST be HTTP 403
- AND the response body MUST contain `"status": "suspended"`

#### Scenario: Test confirms migration output is correct

- GIVEN a test that inserts 2 procest `tenant` objects (one `isActive: true`, one `isActive: false`)
- WHEN the migration script runs
- THEN `OrganisationMapper::findAll()` MUST include 2 new Organisations
- AND the first MUST have `status: "active"`, the second MUST have `status: "suspended"`
- AND all non-status fields MUST match the source tenant objects

