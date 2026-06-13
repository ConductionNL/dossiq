# tenant-provisioning Specification

## Purpose
TBD - created by archiving change tenant-zaaksysteem-saas-03-schema-provisioning. Update Purpose after archive.
## Requirements
### Requirement: Schema-per-tenant provisioning (REQ-001-B)

The system SHALL provision a dedicated PostgreSQL schema per tenant, clone the application tables into it, seed default templates and roles, and roll back cleanly on failure.

#### Scenario: Schema created and tables cloned

- **GIVEN** a tenant exists and provisioning is invoked
- **WHEN** `TenantProvisioningService.provision(tenantId)` runs
- **THEN** a PostgreSQL schema named `tenant_{uuid}_{slug}` (≤63 chars) SHALL be created
- **AND** the application tables (case, caseType, decision, document, workflowTemplate, …) SHALL be cloned into the tenant schema
- **AND** shared tables (Tenant, TenantConfiguration, TenantQuota, …) SHALL remain in the public schema

#### Scenario: Defaults seeded and welcome email sent

- **GIVEN** the tenant schema has been created
- **WHEN** seeding completes
- **THEN** standard zaaktype templates and a default mandaat-matrix template SHALL be seeded into the tenant schema
- **AND** default roles (tenant_admin, case_handler, viewer) SHALL be created
- **AND** a "Welcome to Zaaksysteem" email SHALL be sent to the tenant admin with a login link

#### Scenario: Rollback on provisioning failure

- **GIVEN** provisioning is in progress
- **WHEN** any step fails
- **THEN** the partially-created schema SHALL be dropped
- **AND** the tenant SHALL remain in status "onboarding" with no half-provisioned schema left behind

### Requirement: Database-per-tenant for enterprise tier (REQ-001-C)

The system SHALL support a database-per-tenant isolation mode for enterprise tenants with vault-stored credentials and per-tenant residency rules.

#### Scenario: Enterprise database isolation

- **GIVEN** a tenant with tier="enterprise" and isolationMode="database"
- **WHEN** provisioning is initiated
- **THEN** a separate database `proc_tenant_{slug}_{uuid_short}` SHALL be created with all schemas initialised
- **AND** per-tenant credentials SHALL be generated and stored in the secure vault
- **AND** replication/backup rules SHALL be set per the tenant's dataResidency (nl or eu)

