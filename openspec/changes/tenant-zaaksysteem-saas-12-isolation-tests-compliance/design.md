# Design: tenant-zaaksysteem-saas-12-isolation-tests-compliance

## Scope of this member

Suite-level verification + hardening + compliance: cross-tenant isolation tests, E2E onboarding, consolidated OpenAPI, security/performance hardening, and the REQ-010 audit/compliance requirements. No new tenant entities; audit entries reuse the existing OpenRegister auditTrail (ADR-022).

## Declarative-first (ADR-031, ADR-001, ADR-022)

Audit logging is provided by OpenRegister's auto-maintained audit trail (ADR-022) — the consuming members already write through the `ObjectService`, so data-access entries are largely declarative. This member adds the tenant_id stamping and the BIO-2.0 enterprise context fields (deviceId, geoLocation, mfaVerified, sessionDuration) as imperative enrichment on the audit entries. Tests, OpenAPI, and hardening are `kind: code`.

## Test suites (ADR-008)

### TenantIsolationTest (integration)
- Two tenants with overlapping case IDs → isolation holds.
- Cross-tenant query → 404.
- Manipulated `WHERE tenant_id='other'` → isolation holds.
- JWT token swap (A's token on B's domain) → rejected.
- Cross-tenant access attempts are audit-logged.
- Added to CI as a mandatory gate.

### TenantOnboardingFlowTest (E2E)
- create tenant → Decidesk webhook → mandate CSV → SSO endpoint → branding → zaaktype → first user → go-live → status=active.

## OpenAPI (ADR-002, ADR-009)

Consolidated `openapi/schemas/tenant-api.yaml`: all Tenant* endpoints with request/response examples, error responses (401/403/404/429), JWT + tenant-claim auth, and the Decidesk/Shillinq webhook endpoints.

## Compliance (REQ-010)

- **REQ-010-A**: every data access (view/edit/delete) writes an auditTrail entry incl. action, actor, actorRole, resource, timestamp, ipAddress, userAgent, and tenant_id.
- **REQ-010-B**: enterprise-tier mutations add deviceId, geoLocation, mfaVerified, sessionDuration; quarterly pen-test scenarios verify isolation.
- **REQ-010-C**: AVG deletion on termination — schema deleted after retention, immutable deletion-confirmation logged (mechanism in member 11; this member asserts + documents it).

## Security + performance hardening (ADR-005)

- Checklist: all queries tenant-scoped; all endpoints validate the tenant claim; all mutations audit-logged; no hardcoded secrets; error messages don't leak tenant info; dependency audit (composer audit); pen-test of isolation (JWT forgery, cross-tenant queries).
- Performance: indexes on shared tables, slow-query profiling, load test 50+ concurrent users, p95/p99 monitoring, documented scaling limits — compared against member 04's baseline.

## Tests

The deliverables of this member ARE the tests (isolation + E2E) plus the audit-logging unit/integration tests and the hardening checklist evidence.
