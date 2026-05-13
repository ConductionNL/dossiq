# Tasks: multi-tenant-saas

## Implementation Tasks

### Schema & Configuration

- [x] **T01**: Add `tenant` schema to `procest_register.json` with fields from design doc. Add `tenant_schema` to SettingsService CONFIG_KEYS and SLUG_TO_CONFIG_KEY.

### Backend Services

- [x] **T02**: Create `lib/Service/TenantService.php` -- Methods: `getTenantForUser(userId)` resolves tenant from `tenant_*` group membership, `createTenant(name, oin, domain)` creates tenant record + OpenRegister register + Nextcloud group, `getTenantConfig(tenantId)` returns tenant-specific settings, `getTenantRegister(tenantId)` returns register ID, `switchContext(tenantId)` for platform admin, `getResourceUsage(tenantId)` returns user count and storage vs limits, `isUserInTenant(userId, tenantId)` checks membership. Uses IGroupManager for Nextcloud groups.

- [x] **T03**: Create `lib/Middleware/TenantMiddleware.php` -- Implements IMiddleware. In `beforeController()`: resolve tenant from current user, inject tenant register ID into controller context. If no tenant found and user is not platform admin, return 404. Skip middleware for public endpoints and platform admin routes.

- [x] **T04**: Create `lib/Controller/TenantController.php` -- Endpoints: `index()` list tenants (admin only), `create()` provision new tenant, `show(id)` get tenant, `update(id)` update config, `provision(id)` create register+group+defaults, `usage(id)` resource usage, `current()` get current user's tenant. Uses TenantService.

### Routes

- [x] **T05**: Add tenant routes to `appinfo/routes.php` -- `/api/tenants`, `/api/tenants/{id}`, `/api/tenants/{id}/provision`, `/api/tenants/{id}/usage`, `/api/tenants/current`. Before SPA catch-all.

### Frontend

- [x] **T06**: Create `src/services/tenantApi.js` -- Export functions for all tenant API endpoints.

- [x] **T07**: Create `src/views/settings/tabs/TenantSettingsTab.vue` -- Platform admin tenant management: list tenants with status, create tenant form (name, OIN, domain, branding), edit tenant config, resource usage display, provision button. Uses NcSelect for NL Design System theme token set selection.

- [x] **T08**: Create `src/views/cases/components/TenantSwitcher.vue` -- Dropdown in app header for platform admins to switch tenant context. Shows current tenant name, lists all tenants. On switch, reloads settings with new tenant's register ID.

### Integration

- [x] **T09**: Register TenantMiddleware in `lib/AppInfo/Application.php` -- Add middleware registration in `register()` method.

## Verification Tasks

- [ ] **V01**: Tenant service resolves correct tenant from user's group membership
- [ ] **V02**: Cross-tenant access returns 404 (not 403)
- [ ] **V03**: Tenant provisioning creates register, group, and default schemas
- [ ] **V04**: Resource limits are enforced (max users, max storage)
- [ ] **V05**: Platform admin can switch tenant context
- [ ] **V06**: Per-tenant branding applies correct NL Design System tokens
