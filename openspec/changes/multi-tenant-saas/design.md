# Design: multi-tenant-saas

## Architecture Overview

Multi-tenancy is achieved through logical isolation using OpenRegister registers as tenant boundaries. Each tenant maps to a Nextcloud group, and the TenantService resolves the current tenant from the authenticated user's group membership.

```
TenantMiddleware (beforeController)
├── Resolves tenant from user's Nextcloud groups
├── Injects tenant register IDs into controller context
└── Blocks cross-tenant access (returns 404)

TenantService
├── getTenantForUser(userId) -- resolve tenant from group
├── createTenant(name, oin, domain) -- provision new tenant
├── getTenantConfig(tenantId) -- get tenant-specific settings
├── switchTenantContext(tenantId) -- platform admin only
└── getResourceUsage(tenantId) -- current usage vs limits

AdminRoot.vue
└── TenantSettingsTab.vue (platform admin: provision/manage tenants)

App.vue
└── TenantSwitcher.vue (platform admin: switch tenant context)
```

## File Map

### New Files

| File | Purpose |
|------|---------|
| `lib/Service/TenantService.php` | Tenant resolution, provisioning, config, resource tracking |
| `lib/Middleware/TenantMiddleware.php` | Request-level tenant isolation enforcement |
| `lib/Controller/TenantController.php` | API for tenant CRUD, provisioning, resource usage |
| `src/views/settings/tabs/TenantSettingsTab.vue` | Admin UI for tenant provisioning and management |
| `src/views/cases/components/TenantSwitcher.vue` | Platform admin tenant context switcher |
| `src/services/tenantApi.js` | Frontend API service for tenant endpoints |

### Modified Files

| File | Changes |
|------|---------|
| `lib/Settings/procest_register.json` | Add `tenant` schema |
| `lib/Service/SettingsService.php` | Add tenant config keys |
| `appinfo/routes.php` | Add tenant management routes |
| `lib/AppInfo/Application.php` | Register TenantMiddleware |

## Data Model

### tenant Schema
- `name` (string) -- Municipality name
- `slug` (string) -- URL-safe identifier
- `oin` (string) -- Organisatie-identificatienummer
- `domain` (string, nullable) -- Custom domain
- `registerId` (string) -- OpenRegister register ID for this tenant
- `groupId` (string) -- Nextcloud group ID (tenant_{slug})
- `brandingTokens` (object) -- NL Design System token overrides
- `logoUrl` (string, nullable) -- Tenant logo URL
- `primaryColor` (string, nullable) -- Primary brand color
- `maxUsers` (integer, default 0) -- Max users (0 = unlimited)
- `maxStorageMb` (integer, default 0) -- Max storage MB (0 = unlimited)
- `isActive` (boolean, default true) -- Whether tenant is active

## Design Decisions

### DD-01: Tenant Resolution via Nextcloud Groups

**Decision**: Tenant identity is determined by the user's membership in a `tenant_{slug}` Nextcloud group.

**Rationale**: Nextcloud already has group management. Using groups avoids a custom user-tenant mapping table. Group admins can manage users within their tenant.

### DD-02: Register-Per-Tenant Isolation

**Decision**: Each tenant gets its own OpenRegister register. All schemas are shared but data is isolated by register.

**Rationale**: OpenRegister's register model naturally supports data isolation. Queries are scoped to the tenant's register ID.

### DD-03: Cross-Tenant Returns 404

**Decision**: Accessing another tenant's data returns 404 (not 403) to prevent information leakage.

**Rationale**: A 403 confirms the resource exists. A 404 reveals nothing about other tenants.

## API Endpoints

| Method | URL | Purpose |
|--------|-----|---------|
| GET | `/api/tenants` | List all tenants (platform admin) |
| POST | `/api/tenants` | Create a new tenant |
| GET | `/api/tenants/{id}` | Get tenant details |
| PUT | `/api/tenants/{id}` | Update tenant config |
| DELETE | `/api/tenants/{id}` | Deactivate a tenant |
| POST | `/api/tenants/{id}/provision` | Provision tenant (create register, group, defaults) |
| GET | `/api/tenants/{id}/usage` | Get resource usage |
| GET | `/api/tenants/current` | Get current user's tenant |
