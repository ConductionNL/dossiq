# multi-tenant-saas Specification

## Purpose
Enable logical data isolation for multiple municipalities on a single Procest/Nextcloud deployment. Each tenant has its own users, cases, configuration, and branding while sharing the platform infrastructure. Cross-tenant access is restricted to platform administrators.

## Context
The SaaS delivery model (shared platform) requires serving multiple municipalities from a single deployment. This reduces operational overhead and enables shared updates. Nextcloud's native architecture is single-instance, but OpenRegister's register model provides a natural isolation boundary: each municipality gets its own register(s), and RBAC enforces access control.

## ADDED Requirements

### Requirement: Tenant data isolation
The system MUST ensure complete logical data isolation between tenants.

#### Scenario: Tenant-scoped queries
- GIVEN municipality A and municipality B on the same deployment
- WHEN a case worker from municipality A queries cases
- THEN only cases belonging to municipality A's registers MUST be returned
- AND no data from municipality B MUST be visible in any API response or UI view

#### Scenario: Tenant-scoped object creation
- GIVEN a case worker from municipality A creating a new case
- WHEN the case is created
- THEN the case MUST be stored in municipality A's register
- AND the tenant identifier MUST be set automatically (not user-selectable)

#### Scenario: Cross-tenant prevention
- GIVEN a case worker from municipality A who knows a case UUID from municipality B
- WHEN they attempt to access that case via direct API call
- THEN the system MUST return 404 (not 403, to prevent information leakage)

### Requirement: Tenant configuration
Each tenant MUST have independent configuration for zaaktypes, workflows, templates, and branding.

#### Scenario: Tenant-specific zaaktype configuration
- GIVEN municipality A with zaaktype "Evenementenvergunning" configured with 5 stages
- AND municipality B with zaaktype "Evenementenvergunning" configured with 3 stages
- WHEN each municipality's case workers view available zaaktypes
- THEN each MUST see only their own municipality's configuration

#### Scenario: Tenant branding
- GIVEN municipality A with logo "gemeente-a.svg" and primary color "#003366"
- AND municipality B with logo "gemeente-b.svg" and primary color "#009933"
- WHEN each municipality's users access the platform
- THEN the UI MUST display the correct logo and color scheme per tenant
- AND NL Design System tokens MUST be loaded per tenant

### Requirement: Tenant user management
Users MUST be scoped to their tenant with appropriate access controls.

#### Scenario: Tenant admin manages users
- GIVEN a tenant admin for municipality A
- WHEN they access user management
- THEN they MUST only see users belonging to municipality A
- AND they MUST be able to create, modify, and deactivate users within their tenant

#### Scenario: Platform admin cross-tenant access
- GIVEN a platform administrator
- WHEN they access the admin panel
- THEN they MUST be able to switch between tenants
- AND they MUST be able to view aggregated platform statistics across all tenants
- AND all cross-tenant actions MUST be logged in the audit trail

### Requirement: Tenant provisioning
The platform MUST support creating and configuring new tenants.

#### Scenario: Create a new tenant
- GIVEN a platform administrator
- WHEN they create a new tenant with name "Gemeente Eindhoven", OIN, and domain
- THEN the system MUST create a dedicated register in OpenRegister
- AND default schemas (zaak, document, betrokkene) MUST be initialized
- AND a tenant admin user MUST be created
- AND the tenant MUST be accessible via its configured domain or URL path

#### Scenario: Tenant resource limits
- GIVEN a tenant configuration
- WHEN the platform admin sets resource limits (max users: 50, max storage: 10 GB)
- THEN the system MUST enforce these limits
- AND the tenant admin MUST see current usage vs. limits in their dashboard

### Requirement: Shared resources
Certain resources MUST be shareable across tenants for efficiency.

#### Scenario: Shared zaaktype templates
- GIVEN a platform-level zaaktype template "WOO verzoek"
- WHEN a tenant admin activates the template
- THEN the template MUST be copied into the tenant's configuration
- AND modifications to the tenant's copy MUST NOT affect other tenants or the template

## Non-Requirements
- This spec does NOT cover database-per-tenant isolation (PostgreSQL schemas or separate databases)
- This spec does NOT cover multi-region deployment or data residency requirements
- This spec does NOT cover billing or subscription management

## Dependencies
- OpenRegister registers as tenant isolation boundary
- OpenRegister RBAC for access control enforcement
- NL Design System tokens for per-tenant branding
- Nextcloud group-based user management

---

### Current Implementation Status

**Not yet implemented.** Multi-tenancy is not currently built into Procest. The following foundational elements exist that could support future multi-tenant work:

- The app uses a single `procest` register in OpenRegister (defined in `lib/Settings/procest_register.json`). Tenant isolation would require creating per-tenant registers.
- The `InitializeSettings` repair step (`lib/Repair/InitializeSettings.php`) creates the register via `SettingsService.loadConfiguration()` but does not support tenant-scoped register creation.
- The frontend object store (`src/store/modules/object.js`) uses `createObjectStore('object')` from `@conduction/nextcloud-vue` which queries a single register/schema pair. No tenant switching logic exists.
- The settings store (`src/store/modules/settings.js`) fetches from `/apps/procest/api/settings` -- a single global config, not per-tenant.
- No tenant provisioning UI or API exists.
- NL Design System theming is supported at the app level via the `nldesign` submodule, but not per-tenant.
- Nextcloud groups exist but are not used for tenant scoping in Procest.

**Partial foundations:**
- OpenRegister's register model inherently supports data isolation (one register per tenant is the natural boundary).
- ZGW API controllers (`lib/Controller/ZrcController.php`, `ZtcController.php`, etc.) use `ZgwService` which reads register/schema IDs from settings -- these could be made tenant-aware.

### Standards & References

- **ZGW APIs** (VNG Realisatie): Multi-tenant ZGW deployments are common in Dutch government; the Catalogi API supports multiple catalogues per instance.
- **Common Ground**: The information layer principle supports data isolation via separate registers per organization.
- **ISO 27001**: Data isolation requirements for SaaS platforms handling government data.
- **BIO (Baseline Informatiebeveiliging Overheid)**: Dutch government security baseline requiring logical data separation between organizations.
- **AVG/GDPR**: Data processing agreements require clear tenant boundaries for personal data.
- **NL Design System**: Per-organization theming tokens are a standard pattern in Dutch government web applications.
- **Nextcloud multi-tenant patterns**: Nextcloud does not natively support multi-tenancy; this is typically achieved via app-level isolation or separate instances.

### Specificity Assessment

- **Not implementable as-is.** The spec describes high-level requirements but lacks critical implementation details:
  - How tenant identity is determined (URL path, subdomain, HTTP header, Nextcloud group membership?)
  - How the repair step / provisioning creates per-tenant registers programmatically
  - How the frontend switches tenant context (does it get a different register ID from settings?)
  - How tenant-scoped queries are enforced at the API layer (middleware? OpenRegister RBAC?)
  - How shared zaaktype templates are stored and copied (separate "platform" register?)
- **Open questions:**
  - Should tenants share a single Nextcloud instance (users in groups) or have separate Nextcloud instances?
  - How does Nextcloud user management interact with tenant scoping? Can a user belong to multiple tenants?
  - What is the resource limit enforcement mechanism (Nextcloud quota? OpenRegister-level limits?)?
  - How does audit trail capture cross-tenant admin actions?
  - Is this feature even needed given that most municipalities run their own Nextcloud instances?
