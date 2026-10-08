## ADDED Requirements

### Requirement: The tenant migration runs on upgrade for one release and reports what is left (REQ-TOO-001)

Decided by Ruben on 2026-10-08 (Q1). A repair step SHALL run the tenant migration on every upgrade
for one release and SHALL report `unmigrated`: the number of stored tenants with no Organisation of
the same uuid, refused collisions included. `occ dossiq:migrate-tenants` SHALL report the same count
and SHALL accept `--dry-run`, which writes nothing to OpenRegister. `TenantMigrationService` SHALL
be removed in the release after, and only once a person has recorded `unmigrated = 0`.

#### Scenario: A dry run writes nothing
- **GIVEN** two stored tenants, one of whose slugs is held by another Organisation
- **WHEN** an operator runs `occ dossiq:migrate-tenants --dry-run`
- **THEN** the output SHALL list one mapping, one `REFUSED` collision and `unmigrated = 2`
- **AND** no Organisation SHALL be inserted or updated and no object SHALL be saved

#### Scenario: The upgrade migrates and counts what is left
- **GIVEN** the same two tenants
- **WHEN** the post-migration repair step runs on upgrade
- **THEN** one tenant SHALL be migrated and the step SHALL report `unmigrated = 1`
- **AND** a second run SHALL migrate nothing and report `unmigrated = 1` again

#### Scenario: The migration code outlives the release only while something is left
- **GIVEN** the issue records no `unmigrated = 0` from the repair step
- **WHEN** a build session reaches task 6.4
- **THEN** it SHALL stop and leave `TenantMigrationService` in place

### Requirement: Tenant objects stay as the read-only anchor of the tenant audit trail (REQ-TOO-002)

Decided by Ruben on 2026-10-08 (Q4). dossiq SHALL NOT delete a stored tenant object, and once the
local admin store is gone no code SHALL write one. The `tenant` schema SHALL stay declared and SHALL
be described as the read-only audit anchor. No other property SHALL carry `$ref: tenant`:
`automaticAction.tenantId` and `tenantOnboardingTask.tenantRef` SHALL reference `nc-organisation`,
keeping their stored values, because the migration kept every tenant uuid on its Organisation.

#### Scenario: The descriptors keep the anchor and nothing else points at it
- **GIVEN** `lib/Settings/dossiq_register.json` and `lib/Settings/dossiq_mock_register.json`
- **WHEN** every property of every schema is read
- **THEN** no property SHALL carry `$ref: tenant`
- **AND** the `tenant` schema SHALL be declared and described as the read-only audit anchor

#### Scenario: A migrated tenant's audit history keeps resolving
- **GIVEN** a migrated tenant whose tenant object is stored and whose Organisation carries the same uuid
- **WHEN** `MandateValidationMiddleware` records a mandate decision for it after the admin store is gone
- **THEN** `TenantAuditTrailService` SHALL write the row against that tenant object and answer `persisted: true`

#### Scenario: Nothing deletes a tenant object
- **GIVEN** the codebase after this change
- **WHEN** `lib/` is searched for a write or delete on the `tenant` schema
- **THEN** none SHALL be found

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

`openspec/architecture/adr-004-tenant-cluster-adr-022-exception.md` SHALL cover
`TenantOrganisationResolver` for rule 4 once its fallback is gone, SHALL cover
`TenantMigrationService` for rule 4 for the one release it runs as a repair step, SHALL record that
tenant objects are kept read-only as the audit anchor, and SHALL name no file that no longer exists.

#### Scenario: The gate output matches the ADR
- **GIVEN** this change merged on `development`
- **WHEN** gate 23 runs with `--base origin/development`
- **THEN** `TenantOrganisationResolver.php` and `TenantMigrationService.php` SHALL be printed as suppressed for rule 4 only
- **AND** neither `TenantSaasService.php` nor `TenantSaasController.php` SHALL be listed
