---
status: done
status-note: Reverse-synced 2026-06-13 from an archived fully-implemented change; capability code confirmed present on development.
---
# tenant-compliance Specification

## Purpose
TBD - created by archiving change tenant-zaaksysteem-saas-12-isolation-tests-compliance. Update Purpose after archive.
## Requirements
### Requirement: Cross-tenant isolation test suite (REQ-002-VERIFY)

The system SHALL include a mandatory CI integration suite proving no cross-tenant data leakage.

#### Scenario: Isolation suite covers leakage vectors

- **GIVEN** two tenants with overlapping case IDs
- **WHEN** the isolation suite runs
- **THEN** it SHALL assert tenant A never sees tenant B data via overlapping IDs, cross-tenant query (404), injected `WHERE tenant_id='other'`, and JWT token swap (rejected)
- **AND** it SHALL assert cross-tenant access attempts are audit-logged
- **AND** the suite SHALL run as a mandatory CI gate

### Requirement: End-to-end onboarding test and OpenAPI documentation (REQ-003-E2E, REQ-DOC)

The system SHALL include an end-to-end onboarding test and a consolidated OpenAPI 3.0 spec for all tenant endpoints.

#### Scenario: Full onboarding flow verified end to end

- **GIVEN** the E2E onboarding test runs
- **WHEN** it executes create tenant → contract webhook → mandate → SSO → branding → zaaktype → first user → go-live
- **THEN** the tenant SHALL end with status="active"
- **AND** all tenant endpoints SHALL be documented in OpenAPI 3.0 with auth, webhook, and error responses (401/403/404/429)

### Requirement: Audit logging and regulatory compliance (REQ-010-A, REQ-010-B, REQ-010-C)

The system SHALL maintain tenant-stamped audit trails, BIO 2.0 enterprise context, and AVG deletion confirmation.

#### Scenario: Data access is audit-logged with tenant context

- **GIVEN** a case handler views a case
- **WHEN** the access occurs
- **THEN** an auditTrail entry SHALL record action, actor, actorRole, resource, timestamp, ipAddress, userAgent, and tenant_id

#### Scenario: Enterprise mutations capture BIO context

- **GIVEN** an enterprise-tier tenant
- **WHEN** a data modification occurs
- **THEN** the auditTrail entry SHALL additionally include deviceId, geoLocation, mfaVerified, and sessionDuration

#### Scenario: AVG deletion confirmed on termination

- **GIVEN** a terminated tenant's retention period elapses
- **WHEN** the schema is deleted
- **THEN** a deletion confirmation SHALL be recorded in an immutable audit store

