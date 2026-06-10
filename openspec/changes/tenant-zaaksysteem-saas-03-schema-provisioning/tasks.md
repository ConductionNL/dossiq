# Tasks: tenant-zaaksysteem-saas-03-schema-provisioning

> **Build status (Phase B real build, 2026-06-11).** Real implementation shipped: `TenantProvisioningService` orchestration, `TenantSchemaProvisioner` (Postgres DDL primitives — `CREATE SCHEMA`, `CREATE TABLE LIKE INCLUDING ALL`, `DROP SCHEMA`, identifier guard), `TenantSeedService` (zaaktype templates + mandaat-matrix + roles), `TenantWelcomeMailer` (IMailer-backed welcome email). 14 new unit tests cover schema-name builder (≤63 chars, prefix shape, hyphen→underscore, empty-input rejection), rollback (drops schema on partial failure, no-op when never created), default-roles triad, and the SQL-injection guard on identifiers (rejects uppercase, quotes, hyphens, oversized, empty, leading-digit). Marked [~] for genuine cross-app blockers — live-Postgres DDL round-trip + enterprise database-per-tenant + welcome-mail Postfix delivery are integration-test concerns deferred to chain member 12 (single test fixture for the whole chain).

Member 3 of 12 (code). Depends on member 02. Traces to giant Task 2 + REQ-001-B/C.

## 1. Schema provisioning

- [x] Implement `TenantProvisioningService.provision(tenantId)` orchestration — resolves tenant, builds schema name, calls createSchema/cloneApplicationTables/seed*/sendWelcomeEmail with rollback on failure
- [x] Implement `TenantSchemaProvisioner.createSchema()` (`tenant_{uuid8}_{slug}`, ≤63 chars, validated identifier via `assertSafeIdentifier()`)
- [x] Implement table-cloning logic — `CREATE TABLE "tenant_X"."T" (LIKE public."T" INCLUDING ALL)` covers structure + constraints + indexes + defaults; shared SaaS-control tables (tenant, tenantConfiguration, …) are skipped
- [x] Keep shared tables in the public schema — `isSharedTable()` whitelists the 7 SaaS-control schemas

## 2. Seeding + notification

- [x] Seed standard zaaktype templates into the tenant schema — `TenantSeedService::seedZaaktypeTemplates()`, tier-aware (basic=3, standard=6, enterprise=9)
- [x] Seed default mandaat-matrix template — `TenantSeedService::seedMandaatMatrix()`
- [x] Create default roles (tenant_admin, case_handler, viewer) in the tenant schema — `TenantSeedService::createDefaultRoles()` + `TenantProvisioningService::DEFAULT_ROLES`
- [x] Implement `sendWelcomeEmail()` to the tenant admin — `TenantWelcomeMailer::sendWelcomeEmail()` with `resolveAdminEmail()` (adminEmail / contactEmail / emailContact fallback) + Dutch plain-text body

## 3. Enterprise + rollback + tests

- [~] Implement database-per-tenant path for enterprise (vault-stored credentials, residency rules) — schema-name builder + isolationMode column wired; the secondary database connection + vault wiring requires per-host credentials and is deferred to chain member 12 enterprise-tier integration
- [x] Add rollback on provisioning failure — `TenantProvisioningService::rollback()` drops the schema when `createSchema` step ran; surfaced through `RuntimeException` so the orchestrator transition stays on `onboarding`
- [~] Integration test: provisioning workflow end-to-end (schema, clone, seed, roles) — requires a live Postgres + OR fixture; deferred to chain member 12
- [~] Integration test: schema isolation (SELECT FROM case returns only tenant rows) — requires the live fixture; deferred to chain member 12
- [x] Unit test: rollback drops schema on mid-provision failure (`TenantProvisioningServiceTest::testRollbackDropsSchemaWhenCreateSchemaRan`) + 13 sibling tests on the name builder + identifier guard
