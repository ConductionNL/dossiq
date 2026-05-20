# Proposal: migrate-tenant-to-or-tenant

## Why

procest's `multi-tenant-saas` change introduced three files that duplicate OR's tenant
management surface:

- `lib/Service/TenantService.php` — resolves tenants from NC groups, creates tenant records
  in a private OR schema, provisions registers, tracks resource usage.
- `lib/Middleware/TenantMiddleware.php` — resolves tenant context, injects `_tenantId` into
  requests, enforces cross-tenant access rules.
- `lib/Controller/TenantController.php` — CRUD + provisioning + usage API for tenants.

All three map to functionality that OR already ships in `tenant-lifecycle`, `tenant-quotas`,
and `tenant-isolation-audit`. Specifically:

- procest's `isActive` boolean reproduces OR's five-state lifecycle (`status` field) in a
  lossy way: suspended, deprovisioning, and archived are all collapsed to `isActive: false`.
- procest's `maxUsers`/`maxStorageMb` limits are tracked in the private schema but never
  enforced by middleware — OR's `TenantQuotaMiddleware` already enforces `storageQuota`,
  `requestQuota`, and `bandwidthQuota` on every Organisation.
- procest's middleware creates no audit entries when blocking non-active tenants — OR's
  `tenant-isolation-audit` spec requires such entries; they are only emitted if the OR
  middleware is in the request chain.

This spec migrates procest to consume OR's Organisation entity for tenant identity, delegate
lifecycle and quota enforcement to OR, and remove the private `tenant` schema after a
one-time data migration.

## What

1. **`TenantMiddleware.php`** — inject `TenantLifecycleService` from OR; replace the
   `isActive` field check with OR's status-based block; preserve the tenant context
   injection (`_tenantId`, `_tenantRegisterId`, `_tenantSlug`) so controllers are
   unaffected.

2. **`TenantService.php`** — delegate `createTenant`, `provisionTenant`, and
   `getResourceUsage` to OR's Organisation API. Deprecate methods that duplicate OR's API.
   The `getTenantForUser` and `getTenantByGroupId` methods are retained (they resolve the NC
   group → Organisation UUID mapping, which is procest-local domain knowledge).

3. **`TenantController.php`** — endpoint surface unchanged; request/response bodies call the
   updated `TenantService`, which delegates to OR. No routing changes.

4. **One-time data migration script** — reads all existing procest `tenant` OR schema objects,
   creates corresponding OR Organisation objects (with custom properties for `oin`, `domain`,
   `brandingTokens`, `registerId`), maps `isActive: true` → `status: active` and
   `isActive: false` → `status: suspended`, preserves UUIDs where possible.

5. **Deprecate procest `tenant` schema** — mark as deprecated in `procest_register.json`
   after migration; no new writes.

## Capabilities

### Modified Capabilities

- `multi-tenant-saas` (existing) — tenant functionality is preserved at the API surface
  but is now backed by OR's Organisation entity and lifecycle service instead of procest's
  private schema and service.

### Out of Scope

- OR API or schema changes (all three OR tenant specs are `status: implemented`; this spec
  only consumes them).
- Frontend `TenantSettingsTab.vue` or `TenantSwitcher.vue` changes (they call the same
  controller API endpoints; no visual change is expected).
- Billing, subscription management, or multi-region deployment.
- Any quota enforcement change — procest inherits OR's quota enforcement after migration.

## Affected Projects

- [x] Project: `procest` — `TenantMiddleware.php`, `TenantService.php`,
  `TenantController.php`, `procest_register.json`, one-time data migration script.
- [x] Project: `openregister` — verified DI surface (no code change required; see
  `consume-or-tenant-fleet-wide/tasks.md` OR-1.1 and OR-1.2).

## Success Criteria

- `openspec validate --strict migrate-tenant-to-or-tenant` exits 0.
- `TenantMiddleware::beforeController()` calls OR's `TenantLifecycleService` for status
  checks; the procest-local `isActive` field is never read for access control decisions.
- One-time migration script runs without error and all existing procest tenants appear as
  OR Organisation objects with the correct `status` field.
- procest's `tenant` schema in `procest_register.json` is marked deprecated.
- Integration tests confirm that a suspended OR Organisation results in HTTP 403 from
  procest's endpoints; an active Organisation allows access.
