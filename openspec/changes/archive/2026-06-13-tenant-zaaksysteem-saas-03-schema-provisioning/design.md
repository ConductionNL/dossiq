# Design: tenant-zaaksysteem-saas-03-schema-provisioning

## Scope of this member

PostgreSQL schema-per-tenant provisioning: schema creation, table cloning, default seeding, welcome email, rollback. The optional database-per-tenant (enterprise) path is designed here; the search_path request-time wiring is member 04.

## Declarative-first (ADR-031, ADR-001)

Tenant records and seed templates remain OpenRegister objects (member 01). Provisioning is genuinely imperative infrastructure work (DDL: `CREATE SCHEMA`, table cloning) that has no declarative analogue per ADR-031 — this is exactly the "external integration glue / infrastructure" category the ADR keeps as `kind: code`. The seeded zaaktype templates and roles are read from the member-01 register seed and written into the new schema via the OpenRegister `ObjectService` once the schema exists.

## Service layer

### TenantProvisioningService
- `provision(tenantId)` → orchestrates: resolve tenant, build schema name, create schema, clone tables, seed templates/roles, send welcome email; rolls back on any failure.

### TenantSchemaProvisioner
- `createSchema(name)` → `CREATE SCHEMA "tenant_{uuid}_{slug}"` (name ≤63 chars, truncate slug as needed).
- `cloneApplicationTables(schema)` → copies application table structures + constraints from `public`. Shared tables (Tenant, TenantConfiguration, TenantQuota, …) stay in `public`.
- `seedTenantSchema(schema, tier)` → standard zaaktype templates, default mandaat-matrix template, default roles (tenant_admin, case_handler, viewer).

### Database-per-tenant (enterprise, Phase 2)
For `tier=enterprise` + `isolationMode=database`: create a separate database `proc_tenant_{slug}_{uuid_short}`, generate + vault-store per-tenant credentials, initialise all schemas, configure replication/backup per `dataResidency`, set per-database connection pooling.

## Rollback

If any step fails, drop the partially-created schema (or database) and surface a provisioning error; the tenant stays in `onboarding`. No half-provisioned schema is left behind.

## Security (ADR-005)

Provisioning runs in an admin/system context (triggered from onboarding go-live, member 07). DDL is parameterised by a validated schema name derived from the tenant UUID + slugified name — never from raw user input — to avoid SQL injection into the `CREATE SCHEMA` statement. Enterprise per-tenant credentials are stored in the secure vault, never in code or logs (no plaintext secrets — forbidden-patterns gate).

## Tests

- Integration: `provision()` end-to-end creates the schema, clones tables, seeds templates + roles.
- Integration: schema isolation — a `SELECT` against the cloned `case` table in tenant A's schema returns only tenant A rows.
- Unit: rollback drops the schema on a mid-provision failure.
- Unit: schema-name builder truncates to ≤63 chars.
