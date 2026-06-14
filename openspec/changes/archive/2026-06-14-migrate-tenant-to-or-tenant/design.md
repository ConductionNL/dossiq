# Design: migrate-tenant-to-or-tenant

## Context

The umbrella spec `consume-or-tenant-fleet-wide` defines the fleet-wide contract. This
design document details the file-by-file changes for procest.

OR DI identifiers used in this migration (verified via `consume-or-tenant-fleet-wide`
OR-1.1):

- `OCA\OpenRegister\Service\TenantLifecycleService` — `isActive(string $uuid): bool`,
  `activate(string $uuid)`, `suspend(string $uuid)`, etc.
- `OCA\OpenRegister\Db\OrganisationMapper` — CRUD for Organisation entities.
- `OCA\OpenRegister\Service\ObjectService` — already used by procest for object persistence.

## File-by-File Changes

### `lib/Middleware/TenantMiddleware.php`

**Current behaviour**: resolves tenant via `TenantService::getTenantForUser()`, injects
`_tenantId`/`_tenantRegisterId`/`_tenantSlug` into request params. Checks nothing about
Organisation status — the `isActive` field is never read inside this middleware; the
middleware simply passes through regardless of status.

**After migration**:
1. Inject `OCA\OpenRegister\Service\TenantLifecycleService` as a constructor argument.
2. After resolving the Organisation UUID from `getTenantForUser()`, call
   `$tenantLifecycleService->isActive($organisationUuid)`.
3. If `isActive()` returns `false`:
   - Read the Organisation's current `status` via OR's `OrganisationMapper`.
   - Return HTTP 403 with body:
     `{"error": "Organisation is {status}", "status": "{status}"}` matching OR's
     `tenant-isolation-audit` standard error format.
   - Do NOT create a procest-local audit entry (OR's `TenantQuotaMiddleware` upstream
     already creates the audit entry per `tenant-isolation-audit` spec).
4. The `_tenantId` injected into request params is the OR Organisation UUID (not a
   procest-local ID). If procest's migration preserved UUIDs (see one-time migration below),
   this is a no-op change for downstream code.

**Exempt controllers list** is unchanged — `TenantController` remains exempt so provisioning
API calls can reach it before tenant context is established.

### `lib/Service/TenantService.php`

**Current behaviour**: stores tenant records in a procest-local `tenant` OR schema, creates
NC groups, provisions registers, tracks `maxUsers`/`maxStorageMb`.

**After migration**:

| Method | Change |
|---|---|
| `getTenantForUser(string $userId): ?array` | Retained. Resolves NC group → Organisation UUID using OR's `OrganisationMapper` via group slug lookup (same logic, different storage). |
| `getTenantByGroupId(string $groupId): ?array` | Retained. Queries OR Organisations by custom property `groupId` (replaces query on private schema). |
| `createTenant(string $name, ?string $oin, ?string $domain): array` | Delegates to `OrganisationMapper::insert()` with `status: "provisioning"`. Creates NC group unchanged. Custom properties: `oin`, `domain`, `brandingTokens`, `groupId`. Returns Organisation serialized as array. |
| `provisionTenant(string $tenantId): array` | Delegates to OR `TenantLifecycleService::activate($tenantId)` after creating the dedicated OR register. Stores `registerId` as custom property on Organisation. |
| `getResourceUsage(string $tenantId): array` | Reads `storageQuota` from OR Organisation; delegates bandwidth/request quota to OR's `GET /api/organisations/{uuid}/usage` passthrough. Returns user count from NC group (unchanged). |
| `isUserInTenant(string $userId, string $tenantId): bool` | Retained unchanged. |
| `isPlatformAdmin(string $userId): bool` | Retained unchanged. |

**Deprecated and removed in this migration**:
- The `tenant_schema` config key reference (no longer needed; Organisation is the schema).
- The `maxUsers`/`maxStorageMb` fields written to the private schema (replaced by OR's
  `storageQuota` field; `maxUsers` is stored as a custom property but not quota-enforced
  by OR — this is by design per the umbrella mapping table).

### `lib/Controller/TenantController.php`

**No API surface changes.** All endpoint paths, HTTP methods, and response shapes are
preserved. The controller calls `TenantService` which internally delegates to OR.

The only change is that `TenantController::create()` now receives an `isActive` field from
clients; this field is ignored — initial status is always `"provisioning"` per OR's
tenant-lifecycle spec. The `provision()` endpoint triggers the lifecycle transition to
`"active"`.

### `lib/Settings/procest_register.json` (or equivalent)

After the one-time migration:
- Mark the `tenant` schema entry with `"deprecated": true` (or the equivalent annotation).
- Set `"deprecatedAt": "2026-05-11"` and `"sunsetVersion": "next-major"`.

### One-Time Data Migration Script

**Location**: `lib/Migration/MigrateTenantToOrganisation.php` (a Nextcloud background job or
a one-shot migration command registered as `occ procest:migrate-tenants`).

**Algorithm**:
1. Read all objects from procest's `tenant` schema via `ObjectService::getObjects()`.
2. For each tenant object:
   a. Check if an OR Organisation already exists with `slug == tenant.slug` (idempotency guard).
   b. If not, call `OrganisationMapper::insert()` with:
      - `uuid` = tenant UUID (preserves existing `_tenantId` references in stored objects)
      - `name` = `tenant.name`
      - `slug` = `tenant.slug`
      - `status` = `"active"` if `tenant.isActive == true`, else `"suspended"`
      - Custom properties: `oin`, `domain`, `brandingTokens`, `registerId`, `groupId`,
        `maxUsers`
   c. Log the mapping: `tenant.uuid → organisation.uuid`.
3. After all tenants migrated, log a summary: `N tenants migrated, M already existed`.
4. The script is idempotent — re-running it is safe (duplicate check in step 2a).
5. The script does NOT mark the `tenant` schema as deprecated — that is a manual step
   after verifying the migration output.

**UUID preservation**: OR's Organisation entity uses a UUID primary key. If procest's tenant
objects were stored in OR with a UUID, that same UUID is used for the Organisation. If UUIDs
cannot be preserved (e.g. schema prevents it), a UUID mapping table is written to
`appdata/procest/tenant_uuid_map.json` and `TenantService::getTenantByGroupId()` reads the
map during a transition period.

## API Compatibility

All existing procest tenant API endpoints remain available with identical paths and response
shapes. Clients do not need changes. The internal storage shifts from procest's private
`tenant` schema to OR's Organisation entity; the serialized response structure is equivalent
(same top-level fields visible to clients).

## Seed Data

No new schemas are introduced. The procest `tenant` schema (existing) is deprecated after
migration. OR's Organisation schema is already present as part of OR's installation. The
one-time migration script is the only data-layer operation.

## Rollback

If the migration script fails partway through, it is safe to re-run. Organisations created
before the failure remain in OR (idempotency guard prevents duplicates). The procest `tenant`
schema is not marked deprecated until the migration summary confirms all tenants were
processed successfully.
