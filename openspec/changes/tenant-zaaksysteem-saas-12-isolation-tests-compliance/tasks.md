# Tasks: tenant-zaaksysteem-saas-12-isolation-tests-compliance

Member 12 of 12 (code, final). Depends on member 11. Traces to giant Task 21, 22, 23, 24, 25 + REQ-010.

## 1. Isolation + E2E tests

- [ ] `TenantIsolationTest`: two tenants overlapping IDs → isolation holds
- [ ] `TenantIsolationTest`: cross-tenant query → 404; injected `WHERE tenant_id='other'` → isolation; JWT token swap → rejected
- [ ] `TenantIsolationTest`: cross-tenant attempts audit-logged; add suite to CI as mandatory gate
- [ ] `TenantOnboardingFlowTest`: full create → contract → mandate → SSO → branding → zaaktype → user → go-live → active

## 2. OpenAPI documentation

- [ ] Consolidate all Tenant* endpoints (CRUD, onboarding, config, quotas, billing, lifecycle) in OpenAPI 3.0
- [ ] Document request/response examples + error responses (401/403/404/429)
- [ ] Document JWT + tenant-claim auth and the Decidesk/Shillinq webhook endpoints

## 3. Compliance + hardening

- [ ] REQ-010-A: tenant-stamped auditTrail on data access (action, actor, role, resource, ts, ip, ua, tenant_id)
- [ ] REQ-010-B: enterprise BIO context (deviceId, geoLocation, mfaVerified, sessionDuration)
- [ ] REQ-010-C: AVG deletion-on-termination immutable confirmation (assert + document)
- [ ] Security hardening checklist (tenant-scoped queries, claim validation, audit-logged mutations, no hardcoded secrets, no tenant-info leak, composer audit, isolation pen-test)
- [ ] Performance: indexes, slow-query profiling, 50+ concurrent load test, p95/p99 monitoring, scaling docs
