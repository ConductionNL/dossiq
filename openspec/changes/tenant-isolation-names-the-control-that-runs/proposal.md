---
kind: code
depends_on: []
---

# Proposal: tenant-isolation-names-the-control-that-runs

Gate 23 prerequisite, built before the Woo changes (Ruben, 2026-10-08). Resolves dossiq#2470.

## Summary

dossiq deletes its schema-per-tenant isolation, which never isolated anything, and its hardening checklist stops citing it: the checklist names OpenRegister's organisation row filter, the control that actually runs, and reports it as verified only when a live probe says it is on.

- Gate 23 (`or-abstraction-anti-patterns`): clears rule 7 `or-capability:tenant-boundary` by content (`search_path` in `TenantIsolationMiddleware` and in `TenantAuditTrailService` lines 276 and 302). Removes five of the 18 rule 4 files and one of the four rule 7 name hits. It does not clear rule 4 or the rule 7 name hits on its own; see `dossiq/tenancy-onto-openregister-organisation`.
- Resolves https://github.com/ConductionNL/dossiq/issues/2470.
- Dependencies: none. `dossiq/tenancy-onto-openregister-organisation` builds after this.
- Decisions: Ruben 2026-10-08 (gate 23 work first); ADR-004 ("deleted by dossiq#2470, not exempted"); tenancy decision 3 (the session leads); the removal of `archiveAndDelete()` (termination is non-destructive).
- Build rules: openspec/woo-build-rules.md

## Why

Read on `development` at 0f95836e9.

- `TenantIsolationMiddleware::applySearchPath()` runs `SET search_path TO "<tenant_schema>", public`
  on every bound request. The schema it names is created only by
  `TenantSchemaProvisioner::createSchema()`, which is reached only from
  `TenantProvisioningService::provision()`, which has no caller. The only `->provision(` in `lib/`
  is `TenantService.php:175`, and that is OpenRegister's `TenantLifecycleService::provision()`.
  Postgres skips a schema on the path that does not exist, so every request resolves in `public`.
  The control logs a line and changes nothing.
- The SQL is a plain `SET`, which outlives the transaction on a pooled connection, while the
  docblock says `SET LOCAL`. The reset is best effort.
- `TenantSeedService` only logs its intent, and `TenantWelcomeMailer` is reached only from
  `provision()`. The whole pipeline is unreachable.
- `TenantContextMiddleware` calls `TenantProvisioningService::buildSchemaName()` only to hand a
  schema name to `TenantContext::bind()`, for the isolation middleware to read.
- `TenantAuditTrailService::hardeningChecklist()` reports `tenant_scoped_queries` and
  `no_tenant_info_leak` as `pass`, citing the search_path middleware (lines 276 and 302). The one
  artefact an auditor would read cites a control that does nothing. It has no caller in `lib/`
  today, only its tests; this change corrects what it says and does not add a surface.
- What actually isolates is OpenRegister's organisation row filter: `MagicSearchHandler` adds a
  WHERE on the organisation column when multitenancy is on. It is a column filter, not a namespace,
  and it has known escapes (public-read schemas, `_multitenancy: false`). The checklist should name
  it and probe it, not assume it.
- `TenantSaasController#destroy` hard-deletes the tenant row through `TenantSaasService::delete()`,
  with no lifecycle gate and no cascade. It orphans every satellite row keyed by `tenantRef` and the
  audit anchor of every tenant audit entry. dossiq removed `archiveAndDelete()` for being an
  irreversible whole-tenant delete; this route is the same act by another door.
- `docs/leverancier-zaakportaal/deployment.md` lines 95 and 96 tell operators to wait for a
  `TENANT_SCHEMA_DELETED` log line from `archiveAndDelete()`, which no longer exists.
- `openspec/specs/tenant-isolation/spec.md` REQ-002-A requires the search_path isolation, and
  writes the path in the wrong order (`public,<tenant>`). The requirement is removed, not repaired.

Schema-per-tenant is not the intended model: ADR-004 says the pipeline "is deleted by dossiq#2470,
not exempted", and the tenancy change moves the tenant onto OpenRegister's `Organisation`, whose
isolation is the row filter.

## What changes

- Delete `TenantIsolationMiddleware`, `TenantSchemaProvisioner`, `TenantProvisioningService`,
  `TenantSeedService` and `TenantWelcomeMailer`, their registrations and their tests.
- `TenantContext` loses its schema name: `bind()` takes the tenant only and `getSchemaName()` goes.
  `TenantContextMiddleware` stops building one.
- `hardeningChecklist()` cites the OpenRegister row filter, probed live through OpenRegister's
  `SettingsService::isMultiTenancyEnabled()`, and fails closed to `unverified`.
- Remove the `tenantSaas#destroy` route and `TenantSaasService::delete()`. Ending a tenancy is a
  status change, never a delete.
- Fix the deployment doc and the `tenantUser.role` description that names the deleted class.
- Remove REQ-002-A and REQ-002-A-CONTEXT from `tenant-isolation`, and restate the context without a schema as REQ-TIS-004.

## What does not change

- Choosing zaaktype templates by `tier` stays a dossiq concept (tenancy decision 2a). No code runs
  it today: the seeding lived in the unreachable pipeline and only logged. A later change can seed
  beside `TenantService::provisionTenant()` if it is wanted.
- `TenantContextMiddleware`, `TenantMiddleware` and the claim middleware stay. Their end state is
  the tenancy change's open decisions Q2 and Q3.
- OpenRegister is not changed.

## Gate 23 effect

| Finding | Before | After this change |
| --- | --- | --- |
| rule 7 tenant-boundary, `search_path` content | 2 files | 0 |
| rule 7 tenant-boundary, `Tenant*Middleware*` name | 4 files | 3 |
| rule 4 `Tenant*.php` | 18 files | 13 |
