# Tasks: tenant-zaaksysteem-saas-03-schema-provisioning

> **Build status (hydra audit).** The basic TenantService + TenantMiddleware + TenantController shipped via the sibling 'migrate-tenant-to-or-tenant' change (delegates to OR's TenantLifecycleService). This 12-member SaaS chain layers on the full SaaS shape — Tenant/TenantConfiguration/TenantQuota/TenantUser schemas, schema-per-tenant provisioning, JWT tenant-claim auth, mandate validation, onboarding workflow, branding, quota enforcement, shillinq billing, suspension/termination, isolation tests — none of which exist on dev yet. Tasks stay [ ] as genuine forward work.

Member 3 of 12 (code). Depends on member 02. Traces to giant Task 2 + REQ-001-B/C.

## 1. Schema provisioning

- [ ] Implement `TenantProvisioningService.provision(tenantId)` orchestration
- [ ] Implement `TenantSchemaProvisioner.createSchema()` (`tenant_{uuid}_{slug}`, ≤63 chars, validated name)
- [ ] Implement table-cloning logic (copy application table structures + constraints from public)
- [ ] Keep shared tables in the public schema

## 2. Seeding + notification

- [ ] Seed standard zaaktype templates into the tenant schema
- [ ] Seed default mandaat-matrix template
- [ ] Create default roles (tenant_admin, case_handler, viewer) in the tenant schema
- [ ] Implement `sendWelcomeEmail()` to the tenant admin

## 3. Enterprise + rollback + tests

- [ ] Implement database-per-tenant path for enterprise (vault-stored credentials, residency rules)
- [ ] Add rollback on provisioning failure (drop partial schema, tenant stays onboarding)
- [ ] Integration test: provisioning workflow end-to-end (schema, clone, seed, roles)
- [ ] Integration test: schema isolation (SELECT FROM case returns only tenant rows)
- [ ] Unit test: rollback drops schema on mid-provision failure
