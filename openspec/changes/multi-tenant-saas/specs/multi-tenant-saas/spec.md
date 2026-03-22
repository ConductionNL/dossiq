---
status: implemented
---
# multi-tenant-saas Specification

## Purpose
Enable logical data isolation for multiple municipalities on a single Procest/Nextcloud deployment. Each tenant has its own users, cases, configuration, and branding while sharing the platform infrastructure. Cross-tenant access is restricted to platform administrators.

## Context
The SaaS delivery model (shared platform) requires serving multiple municipalities from a single deployment. This reduces operational overhead and enables shared updates. Nextcloud's native architecture is single-instance, but OpenRegister's register model provides a natural isolation boundary: each municipality gets its own register(s), and RBAC enforces access control. The `SettingsService` currently manages a single `procest` register with 26 schemas (case, task, status, role, result, decision, caseType, etc.) -- multi-tenancy requires replicating this register structure per tenant.

## Requirements

### Requirement: Tenant data isolation via OpenRegister registers
The system MUST ensure complete logical data isolation between tenants by assigning each tenant a dedicated OpenRegister register with its own schema set.

#### Scenario: Tenant-scoped queries return only tenant data
- GIVEN municipality A and municipality B each have their own OpenRegister register
- WHEN a case worker from municipality A queries cases via the Procest API
- THEN only cases stored in municipality A's register MUST be returned
- AND no data from municipality B MUST be visible in any API response or UI view
- AND the register ID used for queries MUST be resolved from the user's tenant context

#### Scenario: Tenant-scoped object creation stamps register automatically
- GIVEN a case worker from municipality A creating a new case
- WHEN the case is saved via OpenRegister's ObjectService
- THEN the case MUST be stored in municipality A's register
- AND the register ID MUST be resolved automatically from the user's tenant membership
- AND the case worker MUST NOT be able to select or override the target register

#### Scenario: Cross-tenant access returns 404 to prevent information leakage
- GIVEN a case worker from municipality A who knows a case UUID from municipality B
- WHEN they attempt to access that case via direct API call (e.g., `GET /api/objects/{register}/{schema}/{uuid}`)
- THEN the system MUST return HTTP 404 (not 403, to prevent confirming the object exists)
- AND the access attempt MUST be logged in the security audit trail

#### Scenario: ZGW API endpoints enforce tenant scoping
- GIVEN the ZrcController, ZtcController, BrcController, and DrcController serve ZGW-compatible APIs
- WHEN an external system authenticates and queries cases via ZGW endpoints
- THEN the ZgwService MUST resolve the tenant's register and schema IDs from the authenticated context
- AND cross-tenant data MUST never be returned even if the external system provides valid object references

#### Scenario: Database-level query isolation
- GIVEN OpenRegister stores all tenants' objects in the same PostgreSQL database
- WHEN any query is executed against the objects table
- THEN the query MUST include a register ID filter as a mandatory WHERE clause
- AND no query path (including search, listing, and aggregation) MUST bypass the register filter

### Requirement: Tenant identity resolution via Nextcloud groups
The system MUST determine a user's tenant based on Nextcloud group membership, using a configurable group naming convention.

#### Scenario: User belongs to exactly one tenant group
- GIVEN user "j.jansen" is a member of Nextcloud group `tenant_gemeente_utrecht`
- AND the tenant group prefix is configured as `tenant_`
- WHEN "j.jansen" accesses Procest
- THEN the system MUST resolve their tenant as "gemeente_utrecht"
- AND load the corresponding register ID from the tenant configuration

#### Scenario: User belongs to no tenant group
- GIVEN user "admin" has no group matching the `tenant_` prefix
- WHEN "admin" accesses Procest
- THEN the system MUST deny access to case management features
- AND display a message: "U bent niet gekoppeld aan een organisatie. Neem contact op met uw beheerder."

#### Scenario: User belongs to multiple tenant groups (platform admin)
- GIVEN user "p.admin" is a member of groups `tenant_gemeente_utrecht` and `tenant_gemeente_amsterdam` and `platform_admin`
- WHEN "p.admin" accesses Procest
- THEN the system MUST present a tenant selector in the navigation header
- AND all actions MUST operate within the selected tenant's context
- AND tenant switches MUST be logged in the audit trail

### Requirement: Tenant-independent configuration per zaaktype
Each tenant MUST have independent configuration for zaaktypes, status types, result types, role types, and decision types.

#### Scenario: Tenant-specific zaaktype definitions
- GIVEN municipality A has zaaktype "Evenementenvergunning" with 5 status types and 3-week processing deadline
- AND municipality B has zaaktype "Evenementenvergunning" with 3 status types and 6-week processing deadline
- WHEN each municipality's case workers view available zaaktypes
- THEN each MUST see only their own municipality's configuration
- AND the configurations MUST be stored as separate caseType objects in each tenant's register

#### Scenario: Tenant configuration does not leak between tenants
- GIVEN municipality A creates a new status type "Wacht op extern advies"
- WHEN municipality B's admin views their status types
- THEN municipality B MUST NOT see municipality A's new status type
- AND the settings API (`/apps/procest/api/settings`) MUST return tenant-scoped schema IDs

#### Scenario: Tenant admin manages configuration independently
- GIVEN a tenant admin for municipality A accesses Settings > Case Types
- WHEN they modify the "Omgevingsvergunning" zaaktype
- THEN only municipality A's configuration MUST be affected
- AND the change MUST be logged with the tenant admin's identity and tenant context

### Requirement: Per-tenant branding via NL Design System tokens
Each tenant MUST be able to apply its own visual identity using NL Design System design tokens.

#### Scenario: Tenant-specific logo and color scheme
- GIVEN municipality A has logo "gemeente-a.svg" and primary color `#003366`
- AND municipality B has logo "gemeente-b.svg" and primary color `#009933`
- WHEN each municipality's users access Procest
- THEN the UI MUST display the correct logo and color scheme per tenant
- AND NL Design System tokens MUST be loaded per tenant from the tenant's configuration

#### Scenario: Tenant branding applies to public-facing pages
- GIVEN a citizen accesses a shared case link for a municipality A case
- WHEN the public case view loads
- THEN the branding MUST reflect municipality A's design tokens
- AND the branding MUST NOT show Procest or platform branding unless configured

#### Scenario: Tenant without custom branding uses defaults
- GIVEN a new tenant has not configured custom branding
- WHEN users access Procest
- THEN the default NL Design System theme (Rijksoverheid tokens) MUST be applied
- AND a warning MUST appear in the tenant admin panel: "Huisstijl niet geconfigureerd"

### Requirement: Tenant user management scoped to organization
Users MUST be scoped to their tenant with appropriate access controls; tenant admins manage only their own users.

#### Scenario: Tenant admin sees only their own users
- GIVEN a tenant admin for municipality A
- WHEN they access user management in Procest settings
- THEN they MUST only see users who are members of the `tenant_gemeente_a` Nextcloud group
- AND they MUST be able to assign roles (behandelaar, coordinator, admin) within the tenant

#### Scenario: Platform admin cross-tenant access with audit trail
- GIVEN a platform administrator with the `platform_admin` group
- WHEN they access the admin panel
- THEN they MUST see a tenant overview with user counts, case counts, and storage usage per tenant
- AND they MUST be able to switch between tenants via a tenant selector
- AND all cross-tenant actions MUST be logged with the platform admin's identity and the target tenant

#### Scenario: User deactivation scopes to tenant only
- GIVEN tenant admin deactivates user "m.bakker" in municipality A
- WHEN "m.bakker" is also a member of another tenant group (unusual but possible)
- THEN only their membership in municipality A's tenant group MUST be affected
- AND their access to other tenants MUST remain unchanged

### Requirement: Automated tenant provisioning
The platform MUST support creating and configuring new tenants through an API and admin interface.

#### Scenario: Provision new tenant with default configuration
- GIVEN a platform administrator
- WHEN they create a new tenant with name "Gemeente Eindhoven", OIN "00000001002306608000", and slug "gemeente-eindhoven"
- THEN the system MUST create a Nextcloud group `tenant_gemeente-eindhoven`
- AND create a dedicated OpenRegister register with all 26 Procest schemas (mirroring `procest_register.json`)
- AND store the register ID and schema IDs in a tenant configuration record
- AND create a tenant admin user account assigned to the new group
- AND the provisioning MUST complete within 30 seconds

#### Scenario: Tenant provisioning via API
- GIVEN the provisioning API endpoint `POST /api/admin/tenants`
- WHEN called with `{"name": "Gemeente Eindhoven", "oin": "00000001002306608000", "slug": "gemeente-eindhoven", "adminEmail": "admin@eindhoven.nl"}`
- THEN the system MUST provision the tenant as described above
- AND return the tenant configuration including register ID and admin credentials
- AND send a welcome email to the admin email address

#### Scenario: Tenant provisioning is idempotent
- GIVEN tenant "gemeente-eindhoven" already exists
- WHEN the provisioning API is called again with the same slug
- THEN the system MUST return HTTP 409 Conflict
- AND the existing tenant MUST NOT be modified

### Requirement: Tenant resource limits and usage monitoring
The platform MUST enforce configurable resource limits per tenant and provide usage dashboards.

#### Scenario: User limit enforcement
- GIVEN a tenant configuration with max users set to 50
- AND the tenant currently has 50 active users
- WHEN the tenant admin attempts to add a 51st user
- THEN the system MUST reject the addition with message "Gebruikerslimiet bereikt (50/50)"
- AND the platform admin MUST be notified

#### Scenario: Storage quota enforcement
- GIVEN a tenant configuration with max storage set to 10 GB
- AND current usage is 9.8 GB
- WHEN a case worker uploads a 300 MB document
- THEN the system MUST reject the upload with message "Opslaglimiet bijna bereikt"
- AND the Nextcloud quota system MUST enforce the limit at the group level

#### Scenario: Usage dashboard shows current vs. limits
- GIVEN a tenant admin views their settings dashboard
- THEN the dashboard MUST show: active users (42/50), storage used (7.2 GB / 10 GB), total cases (1,247), total documents (3,891)
- AND items approaching 80% of limit MUST be highlighted in amber
- AND items exceeding 90% of limit MUST be highlighted in red

### Requirement: Shared resources and template library
Certain resources MUST be shareable across tenants for efficiency while maintaining tenant isolation on copies.

#### Scenario: Platform-level zaaktype template activation
- GIVEN a platform-level zaaktype template "WOO verzoek" maintained by the platform admin
- WHEN a tenant admin activates the template for their tenant
- THEN the template MUST be deep-copied into the tenant's register as a new caseType object
- AND all associated status types, result types, and role types MUST also be copied
- AND modifications to the tenant's copy MUST NOT affect other tenants or the source template

#### Scenario: Template versioning and updates
- GIVEN a platform template "WOO verzoek" is updated from v1.2 to v1.3
- AND tenants A and B both have local copies based on v1.2
- WHEN the platform admin publishes the update
- THEN tenant admins MUST see a notification: "Template 'WOO verzoek' heeft een update (v1.3)"
- AND they MUST be able to review changes and choose to apply or skip the update
- AND applying the update MUST NOT overwrite tenant-specific customizations without confirmation

#### Scenario: Shared reference data remains read-only
- GIVEN platform-level reference data (e.g., BAG address lookup, BRP integration endpoints)
- WHEN a tenant admin views the reference data
- THEN it MUST be read-only at the tenant level
- AND changes MUST only be possible by the platform admin

### Requirement: Tenant deactivation and data retention
The platform MUST support deactivating tenants while preserving data according to retention policies.

#### Scenario: Deactivate tenant
- GIVEN a platform administrator deactivates tenant "gemeente-eindhoven"
- WHEN the deactivation is processed
- THEN all users in the tenant's Nextcloud group MUST be blocked from accessing Procest
- AND the tenant's data MUST remain in OpenRegister (not deleted) for the configured retention period
- AND the tenant MUST NOT appear in active tenant listings but MUST appear in the archive

#### Scenario: Reactivate tenant within retention period
- GIVEN tenant "gemeente-eindhoven" was deactivated 30 days ago
- AND the retention period is 365 days
- WHEN the platform admin reactivates the tenant
- THEN all data MUST be restored to active state
- AND users MUST regain access with their previous roles

#### Scenario: Data purge after retention period
- GIVEN tenant "gemeente-eindhoven" was deactivated 366 days ago
- AND the retention period is 365 days
- WHEN the scheduled purge job runs
- THEN the platform admin MUST receive a confirmation prompt before purging
- AND upon confirmation, all tenant data MUST be permanently deleted from OpenRegister
- AND a purge certificate MUST be generated for compliance records

### Requirement: Cross-tenant reporting for platform administrators
Platform administrators MUST be able to generate aggregated reports across all tenants without accessing individual case data.

#### Scenario: Platform-wide KPI dashboard
- GIVEN the platform serves 12 municipalities
- WHEN the platform admin views the platform dashboard
- THEN aggregated metrics MUST be shown: total active cases per tenant, average processing time per tenant, SLA compliance percentage per tenant
- AND no individual case details MUST be visible

#### Scenario: Tenant comparison report
- GIVEN the platform admin requests a comparison report
- WHEN the report is generated
- THEN it MUST show per-tenant: case volume, average resolution time, overdue percentage, user activity
- AND the report MUST be exportable as CSV and PDF

#### Scenario: Anomaly detection alerts
- GIVEN tenant "gemeente-utrecht" normally processes 200 cases/month
- AND the current month shows 50 cases (75% drop)
- WHEN the daily anomaly check runs
- THEN the platform admin MUST receive an alert highlighting the unusual pattern

## Non-Requirements
- This spec does NOT cover database-per-tenant isolation (PostgreSQL schemas or separate databases)
- This spec does NOT cover multi-region deployment or data residency requirements
- This spec does NOT cover billing or subscription management
- This spec does NOT cover SSO federation between tenant identity providers

## Dependencies
- OpenRegister registers as tenant isolation boundary
- OpenRegister RBAC for access control enforcement
- NL Design System tokens for per-tenant branding
- Nextcloud group-based user management (`OCP\IGroupManager`)
- Nextcloud quota system for storage limits
- SettingsService for per-tenant register/schema ID resolution
- ConfigurationService for automated register provisioning

---

### Current Implementation Status

**Not yet implemented.** Multi-tenancy is not currently built into Procest. The following foundational elements exist that could support future multi-tenant work:

- The app uses a single `procest` register in OpenRegister (defined in `lib/Settings/procest_register.json`). Tenant isolation would require creating per-tenant registers using `ConfigurationService::importFromApp()` with tenant-specific parameters.
- The `InitializeSettings` repair step (`lib/Repair/InitializeSettings.php`) creates the register via `SettingsService.loadConfiguration()` but does not support tenant-scoped register creation.
- The `SettingsService` stores register and schema IDs as global app config keys (e.g., `register`, `case_schema`) via `IAppConfig`. Multi-tenancy requires per-tenant config storage (e.g., keyed by tenant slug).
- The frontend object store (`src/store/modules/object.js`) uses `createObjectStore('object')` from `@conduction/nextcloud-vue` which queries a single register/schema pair. No tenant switching logic exists.
- The settings store (`src/store/modules/settings.js`) fetches from `/apps/procest/api/settings` -- a single global config, not per-tenant.
- No tenant provisioning UI or API exists.
- NL Design System theming is supported at the app level via the `nldesign` submodule, but not per-tenant.
- Nextcloud groups exist but are not used for tenant scoping in Procest.

**Partial foundations:**
- OpenRegister's register model inherently supports data isolation (one register per tenant is the natural boundary).
- ZGW API controllers (`lib/Controller/ZrcController.php`, `ZtcController.php`, etc.) use `ZgwService` which reads register/schema IDs from settings -- these could be made tenant-aware by resolving settings per-tenant.

### Standards & References

- **ZGW APIs** (VNG Realisatie): Multi-tenant ZGW deployments are common in Dutch government; the Catalogi API supports multiple catalogues per instance.
- **Common Ground**: The information layer principle supports data isolation via separate registers per organization.
- **ISO 27001**: Data isolation requirements for SaaS platforms handling government data.
- **BIO (Baseline Informatiebeveiliging Overheid)**: Dutch government security baseline requiring logical data separation between organizations.
- **AVG/GDPR**: Data processing agreements require clear tenant boundaries for personal data.
- **NL Design System**: Per-organization theming tokens are a standard pattern in Dutch government web applications.
- **Nextcloud multi-tenant patterns**: Nextcloud does not natively support multi-tenancy; this is typically achieved via app-level isolation using groups.
- **CMMN 1.1**: No direct multi-tenancy concept, but case plans are scoped to organizational context.
- **Dimpact ZAC**: Uses separate Open Zaak instances per municipality -- a database-per-tenant approach. Procest's register-per-tenant is a lighter-weight alternative.
- **Valtimo/Ritense**: Uses Spring Security multi-tenancy with separate database schemas -- similar logical isolation to OpenRegister's register model.
