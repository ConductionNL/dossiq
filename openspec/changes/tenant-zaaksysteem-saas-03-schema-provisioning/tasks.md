# Tasks: tenant-zaaksysteem-saas-03-schema-provisioning

Member 3 of 12 (code). Depends on member 02. Traces to giant Task 2 + REQ-001-B/C.

## 1. Schema provisioning

- [~] Implement `TenantProvisioningService.provision(tenantId)` orchestration — deferred to downstream cycle / fleet-wide adoption (handoff)
- [~] Implement `TenantSchemaProvisioner.createSchema()` (`tenant_{uuid}_{slug}`, ≤63 chars, validated name) — deferred to downstream cycle / fleet-wide adoption (handoff)
- [~] Implement table-cloning logic (copy application table structures + constraints from public) — deferred to downstream cycle / fleet-wide adoption (handoff)
- [~] Keep shared tables in the public schema — deferred to downstream cycle / fleet-wide adoption (handoff)

## 2. Seeding + notification

- [~] Seed standard zaaktype templates into the tenant schema — deferred to downstream cycle / fleet-wide adoption (handoff)
- [~] Seed default mandaat-matrix template — deferred to downstream cycle / fleet-wide adoption (handoff)
- [~] Create default roles (tenant_admin, case_handler, viewer) in the tenant schema — deferred to downstream cycle / fleet-wide adoption (handoff)
- [~] Implement `sendWelcomeEmail()` to the tenant admin — deferred to downstream cycle / fleet-wide adoption (handoff)

## 3. Enterprise + rollback + tests

- [~] Implement database-per-tenant path for enterprise (vault-stored credentials, residency rules) — deferred to downstream cycle / fleet-wide adoption (handoff)
- [~] Add rollback on provisioning failure (drop partial schema, tenant stays onboarding) — deferred to downstream cycle / fleet-wide adoption (handoff)
- [~] Integration test: provisioning workflow end-to-end (schema, clone, seed, roles) — deferred to downstream cycle / fleet-wide adoption (handoff)
- [~] Integration test: schema isolation (SELECT FROM case returns only tenant rows) — deferred to downstream cycle / fleet-wide adoption (handoff)
- [~] Unit test: rollback drops schema on mid-provision failure — deferred to downstream cycle / fleet-wide adoption (handoff)
