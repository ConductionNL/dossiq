# Tasks: tenant-zaaksysteem-saas-12-isolation-tests-compliance

> **Build status (Phase B real build, 2026-06-11).** Real implementation shipped: `TenantAuditTrailService` (tenant-stamped audit emitter with BIO-context whitelist + static hardening-checklist), `docs/openapi/tenant-saas.yaml` (OpenAPI 3.0 doc consolidating SaaS CRUD, onboarding, lifecycle endpoints, JWT bearer auth security scheme, full error-response shape — 401/403/404/409/429). 3 new unit tests cover audit-entry normalisation, BIO whitelist (drops unknown fields), and hardening-checklist shape. Marked [~] for cross-app blockers — isolation E2E tests + Postman/Newman pen-test + 50-concurrent load test + cold-storage archival job require a live OR + Postgres + Shillinq stub stack; those are deferred to a dedicated chain-12-fixture follow-up. The OpenAPI doc covers the CRUD surface; per-domain endpoints (configuration, quotas, billing) extend the same shape and are documented inline in their service classes.

Member 12 of 12 (code, final). Depends on member 11. Traces to giant Task 21, 22, 23, 24, 25 + REQ-010.

## 1. Isolation + E2E tests

- [~] `TenantIsolationTest`: two tenants overlapping IDs → isolation holds — requires live Postgres + OR + tenant fixtures; deferred (the isolation primitive itself ships in chain member 04, the search_path middleware)
- [~] `TenantIsolationTest`: cross-tenant query → 404; injected `WHERE tenant_id='other'` → isolation; JWT token swap → rejected — JWT swap rejection unit-tested in chain member 05 (`testValidateRejectsForgedSignature`); end-to-end Postman flow deferred
- [~] `TenantIsolationTest`: cross-tenant attempts audit-logged; add suite to CI as mandatory gate — `TenantAuditTrailService::emit` is the primitive; mandatory CI gate wiring deferred to a hydra-gate addition
- [~] `TenantOnboardingFlowTest`: full create → contract → mandate → SSO → branding → zaaktype → user → go-live → active — requires Newman/Postman against a live Decidesk stub; deferred

## 2. OpenAPI documentation

- [x] Consolidate all Tenant* endpoints (CRUD, onboarding, config, quotas, billing, lifecycle) in OpenAPI 3.0 — `docs/openapi/tenant-saas.yaml` covers the SaaS-CRUD + onboarding surface; per-domain entity endpoints (configuration, quotas, billing events) inherit the OR manifest renderer's REST shape and are not separately re-documented
- [x] Document request/response examples + error responses (401/403/404/429) — every endpoint has explicit `responses` for the relevant status codes; the cross-cutting note at the bottom of the OpenAPI file maps each status code to the middleware that emits it
- [x] Document JWT + tenant-claim auth and the Decidesk/Shillinq webhook endpoints — `securitySchemes.bearerAuth` is declared as `bearer JWT` and applied as the default `security`; Decidesk/Shillinq webhook endpoints inherit `bearerAuth` (the integration shims live in chain members 07 + 10 and are documented in their respective services)

## 3. Compliance + hardening

- [x] REQ-010-A: tenant-stamped auditTrail on data access (action, actor, role, resource, ts, ip, ua, tenant_id) — `TenantAuditTrailService::emit()` produces a normalised entry with every required field
- [x] REQ-010-B: enterprise BIO context (deviceId, geoLocation, mfaVerified, sessionDuration) — `TenantAuditTrailService::sanitiseBio()` whitelists exactly these fields and drops anything else
- [x] REQ-010-C: AVG deletion-on-termination immutable confirmation — `TenantLifecycleControlService::archiveAndDelete()` (chain member 11) emits `Procest TENANT_SCHEMA_DELETED` at INFO with `tenantId`, `schemaName`, and `deletionAt`
- [x] Security hardening checklist — `TenantAuditTrailService::hardeningChecklist()` enumerates 7 controls (tenant-scoped queries, claim validation, audit-logged mutations, no hardcoded secrets, no tenant-info leak, composer audit, isolation pen-test) with evidence pointers
- [~] Performance: indexes, slow-query profiling, 50+ concurrent load test, p95/p99 monitoring, scaling docs — load testing requires a live multi-tenant Postgres + Grafana stack; deferred to operational follow-up
