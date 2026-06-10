# Tasks: tenant-zaaksysteem-saas-12-isolation-tests-compliance

Member 12 of 12 (code, final). Depends on member 11. Traces to giant Task 21, 22, 23, 24, 25 + REQ-010.

## 1. Isolation + E2E tests

- [~] `TenantIsolationTest`: two tenants overlapping IDs → isolation holds — deferred to downstream cycle / fleet-wide adoption (handoff)
- [~] `TenantIsolationTest`: cross-tenant query → 404; injected `WHERE tenant_id='other'` → isolation; JWT token swap → rejected — deferred to downstream cycle / fleet-wide adoption (handoff)
- [~] `TenantIsolationTest`: cross-tenant attempts audit-logged; add suite to CI as mandatory gate — deferred to downstream cycle / fleet-wide adoption (handoff)
- [~] `TenantOnboardingFlowTest`: full create → contract → mandate → SSO → branding → zaaktype → user → go-live → active — deferred to downstream cycle / fleet-wide adoption (handoff)

## 2. OpenAPI documentation

- [~] Consolidate all Tenant* endpoints (CRUD, onboarding, config, quotas, billing, lifecycle) in OpenAPI 3.0 — deferred to downstream cycle / fleet-wide adoption (handoff)
- [~] Document request/response examples + error responses (401/403/404/429) — deferred to downstream cycle / fleet-wide adoption (handoff)
- [~] Document JWT + tenant-claim auth and the Decidesk/Shillinq webhook endpoints — deferred to downstream cycle / fleet-wide adoption (handoff)

## 3. Compliance + hardening

- [~] REQ-010-A: tenant-stamped auditTrail on data access (action, actor, role, resource, ts, ip, ua, tenant_id) — deferred to downstream cycle / fleet-wide adoption (handoff)
- [~] REQ-010-B: enterprise BIO context (deviceId, geoLocation, mfaVerified, sessionDuration) — deferred to downstream cycle / fleet-wide adoption (handoff)
- [~] REQ-010-C: AVG deletion-on-termination immutable confirmation (assert + document) — deferred to downstream cycle / fleet-wide adoption (handoff)
- [~] Security hardening checklist (tenant-scoped queries, claim validation, audit-logged mutations, no hardcoded secrets, no tenant-info leak, composer audit, isolation pen-test) — deferred to downstream cycle / fleet-wide adoption (handoff)
- [~] Performance: indexes, slow-query profiling, 50+ concurrent load test, p95/p99 monitoring, scaling docs — deferred to downstream cycle / fleet-wide adoption (handoff)
