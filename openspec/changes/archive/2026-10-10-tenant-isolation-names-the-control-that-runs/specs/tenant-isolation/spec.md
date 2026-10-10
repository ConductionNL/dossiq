## REMOVED Requirements

### Requirement: Query-level isolation via search_path (REQ-002-A)

**Reason**: The schema it switches to is never created, so Postgres resolves every request in
`public` and the control isolates nothing (dossiq#2470). Tenant isolation is OpenRegister's
organisation row filter.

**Migration**: None for data: no tenant schema was ever created. Code that read
`TenantContext::getSchemaName()` reads the tenant id instead.

### Requirement: Request-scoped tenant context (REQ-002-A-CONTEXT)

**Reason**: Its scenarios are written over the search_path isolation, including a benchmark of
`SET search_path` overhead that has nothing left to measure once the statement is gone. It is
restated without a schema as REQ-TIS-004.

**Migration**: REQ-TIS-004 keeps the request-scoped context and the 404-not-403 rule.

## ADDED Requirements

### Requirement: No dossiq code sets a database search_path (REQ-TIS-001)

dossiq SHALL NOT set or reset a Postgres `search_path`, and SHALL NOT ship
`TenantIsolationMiddleware`, `TenantSchemaProvisioner`, `TenantProvisioningService`,
`TenantSeedService` or `TenantWelcomeMailer`.

#### Scenario: The pipeline is gone
- **GIVEN** the codebase after this change
- **WHEN** every code line under `lib/` is searched, whole-line comments excluded
- **THEN** no line SHALL contain `search_path`
- **AND** `MiddlewareRegistrar` SHALL register no isolation middleware

### Requirement: The hardening checklist attests only controls that run (REQ-TIS-002)

`TenantAuditTrailService::hardeningChecklist()` SHALL cite OpenRegister's organisation row filter
as the tenant isolation control, and SHALL report it `pass` only when a live probe of OpenRegister
says multitenancy is on. When the probe answers false, fails, or OpenRegister is absent, the item
SHALL read `unverified`. No item SHALL cite a schema or a search_path.

#### Scenario: Multitenancy is on
- **GIVEN** OpenRegister answers `isMultiTenancyEnabled()` with true
- **WHEN** the checklist is built
- **THEN** `tenant_scoped_queries` SHALL be `pass` and its evidence SHALL name the OpenRegister row filter

#### Scenario: The probe cannot confirm it
- **GIVEN** OpenRegister answers false, or throws, or is not installed
- **WHEN** the checklist is built
- **THEN** `tenant_scoped_queries` and `no_tenant_info_leak` SHALL be `unverified`

### Requirement: Ending a tenancy deletes nothing (REQ-TIS-003)

dossiq SHALL NOT expose a route that deletes a tenant. Ending a tenancy SHALL be a status change.
Operator documentation SHALL NOT name a signal that no code emits.

#### Scenario: No delete route
- **GIVEN** `appinfo/routes.php` after this change
- **WHEN** the tenant routes are read
- **THEN** none SHALL use the verb `DELETE`

#### Scenario: The runbook names only real signals
- **GIVEN** `docs/leverancier-zaakportaal/deployment.md`
- **WHEN** it is searched
- **THEN** it SHALL NOT mention `TENANT_SCHEMA_DELETED` or `archiveAndDelete`

### Requirement: Request-scoped tenant context without a schema (REQ-TIS-004)

The system SHALL maintain a request-scoped tenant context resolved from the session, carrying the
tenant id and slug and no database schema name. Isolation between tenants SHALL be OpenRegister's
organisation row filter, and a lookup of another tenant's resource SHALL answer HTTP 404, not 403,
so tenant existence does not leak.

#### Scenario: The context carries no schema
- **GIVEN** a session whose active tenant resolves
- **WHEN** `TenantContextMiddleware` binds it
- **THEN** `TenantContext` SHALL carry the tenant id and slug
- **AND** no `SET search_path` statement SHALL be issued for the request

#### Scenario: Cross-tenant lookup returns 404
- **GIVEN** a user whose active tenant is A and a case that belongs to tenant B
- **WHEN** the user asks for that case
- **THEN** OpenRegister's row filter SHALL return no row
- **AND** the application SHALL respond HTTP 404, not HTTP 403
