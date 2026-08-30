---
status: done
retrofit: true
---

# Multi-Tenancy Specification

## Purpose

@e2e exclude Tenant provisioning is a backend REST API; UI renders via manifest, not custom Playwright-testable views.

Provide per-tenant isolation for dossiq by mapping each tenant 1:1 to an OpenRegister `Organisation` entity (per ADR-022) and delegating lifecycle state-machine to OR's `TenantLifecycleService`. Dossiq exposes a thin domain surface — provisioning, resource-usage aggregation, current-tenant resolution, and the membership/status helpers `TenantMiddleware` needs to short-circuit suspended tenants — while generic tenant CRUD is rendered by the manifest off the OR object endpoints.

## Requirements

### REQ-001: Tenant REST endpoints (provision / usage / current) with admin guard

The system SHALL expose three JSON endpoints on `TenantController`: `provision(tenantId)` and `usage(tenantId)` requiring platform-admin (NC admin group) — HTTP 403 `{success:false, error:'Admin required'}` otherwise; and `current()` (`@NoAdminRequired`) returning the calling user's tenant or HTTP 401 `{success:false, error:'Not authenticated'}` when anonymous.

#### Scenario: Provision shape

- WHEN `provision(tenantId)` succeeds
- THEN the controller SHALL return `{success: true, tenant: <orgJsonSerialize>}`
- AND on service-level error SHALL return HTTP 500 `{success:false, error:<message>}`

#### Scenario: Usage shape

- WHEN `usage(tenantId)` succeeds
- THEN the controller SHALL return `{success: true, usage: <usage payload>}`

#### Scenario: Current with no assigned tenant

- WHEN the authenticated user has no tenant
- THEN `current()` SHALL return `{success: true, tenant: null, message: 'No tenant assigned'}` (HTTP 200)

### REQ-002: User-to-tenant resolution via OR Organisation with NC-group fallback

The system SHALL resolve `getTenantForUser(userId)` by first asking the OR `OrganisationMapper::findByUserId(userId)`, returning the first match's `jsonSerialize()`; when no OR Organisation matches, it SHALL fall back to scanning the user's NC groups for the `tenant_` prefix and looking up the Organisation whose `groups` array contains that group id.

#### Scenario: OR-first resolution

- WHEN OR returns at least one Organisation for the user
- THEN the service SHALL return its `jsonSerialize()` payload (no NC-group lookup)

#### Scenario: NC-group fallback

- WHEN OR returns no Organisation
- THEN the service SHALL iterate `IGroupManager::getUserGroups($user)`, pick the first group whose id starts with `tenant_`, and return `getTenantByGroupId(groupId)`

#### Scenario: Linear scan inside getTenantByGroupId

- WHEN `getTenantByGroupId` runs
- THEN it SHALL fetch up to 500 Organisations and return the first whose `groups` array contains the supplied id, or `null` when none match

#### Notes

- The 500-Organisation ceiling is acceptable per the existing inline comment `Linear scan is acceptable here — tenant count is small (≤100s)`. Anything beyond 500 would silently miss — flagged for future tightening.

### REQ-003: Tenant provisioning via OR TenantLifecycleService

The system SHALL provision a tenant by loading the Organisation by UUID, deriving an `adminUid` (the Organisation's owner when that user is an NC admin, else literal `'admin'`), and calling `TenantLifecycleService::provision(org, adminUid)`. The service is expected to perform the `provisioning → active` state transition and emit `OrganisationProvisionedEvent`.

#### Scenario: Service unavailable

- WHEN OR's `OrganisationMapper` or `TenantLifecycleService` cannot be resolved from the DI container
- THEN `provisionTenant` SHALL return `{error: 'OpenRegister tenant services unavailable'}` without further work

#### Scenario: Provisioning failure

- WHEN `TenantLifecycleService::provision` throws
- THEN the service SHALL log `'Dossiq: provisionTenant failed via OR'` and return `{error: 'Failed to provision tenant: <message>'}`

#### Scenario: Success

- WHEN provisioning succeeds
- THEN the service SHALL log `'Dossiq: Tenant provisioned via OR TenantLifecycleService'` with `tenantId` + new `status` and return `org->jsonSerialize()`

### REQ-004: Tenant resource-usage aggregation from OR Organisation

The system SHALL compute resource usage by reading the Organisation's storage / bandwidth / request quotas and status from OR, plus counting users across every NC group listed in `Organisation.groups`, returning `{users, storageQuota, bandwidthQuota, requestQuota, status}` with quotas coerced to `int` (default `0`) and status coerced to `string` (default `''`).

#### Scenario: OR unavailable

- WHEN OR cannot resolve the mapper
- THEN `getResourceUsage` SHALL return `{error: 'OpenRegister is not available'}`

#### Scenario: Unknown tenant

- WHEN `findByUuid` throws
- THEN the service SHALL return `{error: 'Organisation not found'}`

#### Scenario: User count

- WHEN computing `users`
- THEN the service SHALL sum `count($group->getUsers())` for every group id in `Organisation.groups`, skipping group ids the NC group manager can't resolve

### REQ-005: Tenant membership and status helpers for middleware

The system SHALL provide three helpers used by `TenantMiddleware` and request scoping: `isUserInTenant(userId, tenantId)` returning `true` only when the user's resolved tenant uuid/id matches; `isPlatformAdmin(userId)` delegating to `IGroupManager::isAdmin`; and `getTenantStatus(tenantId)` returning the Organisation status (`active`, `suspended`, `deprovisioning`, `archived`, ...) or `null` when OR cannot resolve the tenant.

#### Scenario: isUserInTenant

- WHEN the user's resolved tenant carries `uuid == tenantId` or, when uuid is missing, `id == tenantId`
- THEN the helper SHALL return `true`; otherwise `false`

#### Scenario: getTenantStatus

- WHEN OR can load the Organisation
- THEN the helper SHALL return the status string
- AND when OR is unavailable or `findByUuid` throws it SHALL return `null` (so middleware can fail open or closed by policy)

#### Notes

- Both helpers swallow `Throwable` from OR — flagged as observed-but-suspicious: a silent `null` from `getTenantStatus` is indistinguishable from "tenant active". Callers MUST short-circuit on `null` rather than treat it as a transient-skip.
