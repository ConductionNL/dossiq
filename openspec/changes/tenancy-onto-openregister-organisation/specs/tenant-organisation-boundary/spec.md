## ADDED Requirements

### Requirement: The tenant migration runs dry, and a person reads it, before anything is deleted (REQ-TOO-001)

`occ dossiq:migrate-tenants` SHALL accept `--dry-run`. A dry run SHALL read every stored tenant and
report the same mappings, repairs, collisions and orphans the real run would, and SHALL write
nothing to OpenRegister. No step that deletes or retires tenant data SHALL be built before a person
has recorded a dry run and a real run with no refused and no failed tenant.

#### Scenario: A dry run writes nothing
- **GIVEN** two stored tenants, one of whose slugs is held by another Organisation
- **WHEN** an operator runs `occ dossiq:migrate-tenants --dry-run`
- **THEN** the output SHALL list one mapping and one `REFUSED` collision
- **AND** no Organisation SHALL be inserted or updated and no object SHALL be saved

#### Scenario: The destructive half waits for the recorded run
- **GIVEN** this change's issue without a pasted real run showing `refused = 0` and `failed = 0`
- **WHEN** a build session reaches task 6.4
- **THEN** it SHALL stop and leave tasks 6.4 onward unbuilt

### Requirement: No schema references the local tenant schema (REQ-TOO-002)

Neither register descriptor SHALL declare a `tenant` schema once the migration has run, and no
property in either descriptor SHALL carry `$ref: tenant`. `automaticAction.tenantId` and
`tenantOnboardingTask.tenantRef` SHALL reference `nc-organisation`, keeping their stored values,
because the migration kept every tenant uuid on its Organisation.

#### Scenario: The descriptors are free of the tenant schema
- **GIVEN** `lib/Settings/dossiq_register.json` and `lib/Settings/dossiq_mock_register.json`
- **WHEN** every property of every schema is read
- **THEN** no property SHALL carry `$ref: tenant`
- **AND** no schema SHALL be keyed `tenant`

#### Scenario: A stored action keeps resolving
- **GIVEN** an `automaticAction` whose `tenantId` is the uuid of a migrated tenant
- **WHEN** `AutomaticActionFlowMigrator` reads it
- **THEN** the action SHALL resolve against the Organisation with that uuid

### Requirement: The tenant resolves only as an OpenRegister Organisation (REQ-TOO-003)

`TenantOrganisationResolver::resolve()` SHALL read the tenant from OpenRegister's
`OrganisationMapper` and from nothing else. A tenant id with no Organisation SHALL resolve to no
tenant. It SHALL NOT fall back to the local `tenant` schema.

#### Scenario: No Organisation means no tenant
- **GIVEN** a tenant id with no Organisation in OpenRegister
- **WHEN** `TenantContextMiddleware` resolves it
- **THEN** the resolver SHALL answer `null` and the request context SHALL stay unbound
- **AND** the local tenant store SHALL NOT be asked

### Requirement: The local tenant admin store and its surface are gone (REQ-TOO-004)

dossiq SHALL NOT ship `TenantSaasService`, `TenantSaasController`, the `tenantSaas#*` routes, or the
`Tenants`, `TenantDetail` and `TenantsMenu` manifest entries. A tenant's status SHALL be the
Organisation's, governed by OpenRegister's lifecycle. Onboarding SHALL NOT write a tenant status.

#### Scenario: The store has no caller and no route
- **GIVEN** the codebase after this change
- **WHEN** `lib/` and `appinfo/routes.php` are searched
- **THEN** no class SHALL name `TenantSaasService` and no route SHALL name `tenantSaas`

#### Scenario: Activating onboarding writes no status
- **GIVEN** a tenant whose onboarding steps are all complete
- **WHEN** an admin calls the onboarding `activate` route
- **THEN** the answer SHALL be OK
- **AND** no tenant or Organisation status SHALL be written

### Requirement: ADR-004 names exactly what is left (REQ-TOO-005)

`openspec/architecture/adr-004-tenant-cluster-adr-022-exception.md` SHALL list each `Tenant*.php`
file that gate 23 still matches under the open decision it waits on, SHALL cover
`TenantOrganisationResolver` for rule 4 only once its fallback is gone, and SHALL name no file that
no longer exists.

#### Scenario: The gate output matches the ADR
- **GIVEN** this change merged on `development`
- **WHEN** gate 23 runs with `--base origin/development`
- **THEN** every tenant path it still counts SHALL appear in ADR-004 under Q1, Q2 or Q3
- **AND** `TenantOrganisationResolver.php` SHALL be printed as suppressed for rule 4 only
