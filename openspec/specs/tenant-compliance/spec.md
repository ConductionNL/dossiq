---
status: done
status-note: Reverse-synced 2026-06-13 from an archived fully-implemented change; capability code confirmed present on development.
---
# tenant-compliance Specification

## Purpose
Verifies and documents multi-tenant correctness through a mandatory CI suite that proves no cross-tenant data leakage across overlapping IDs, cross-tenant queries, injected tenant filters, and JWT token swaps. It includes an end-to-end onboarding test and a consolidated OpenAPI 3.0 specification for all tenant endpoints, and maintains tenant-stamped audit trails, BIO 2.0 enterprise context, and AVG deletion confirmation for regulatory compliance.
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

The audit trail SHALL be durable and tamper-evident, and the system SHALL NOT attest
to a compliance control it cannot demonstrate at runtime.

#### Scenario: Data access is audit-logged with tenant context

- **GIVEN** a case handler views a case
- **WHEN** the access occurs
- **THEN** an auditTrail entry SHALL record action, actor, actorRole, resource, timestamp, ipAddress, userAgent, and tenant_id

#### Scenario: Enterprise mutations capture BIO context

- **GIVEN** an enterprise-tier tenant
- **WHEN** a data modification occurs
- **THEN** the auditTrail entry SHALL additionally include deviceId, geoLocation, mfaVerified, and sessionDuration

#### Scenario: Audit entries are persisted to a durable, tamper-evident sink

- **GIVEN** a tenant mutation is audited
- **WHEN** the audit entry is emitted
- **THEN** the system MUST write one hash-chained OpenRegister audit-trail row anchored to the tenant object
- **AND** the system MUST NOT treat a log line as the audit record of record
- **AND** the emitted entry MUST report whether the durable row was written

#### Scenario: Tenant provisioning and status changes are audited

- **GIVEN** a tenant is provisioned or its status is changed
- **WHEN** the mutation succeeds
- **THEN** the system MUST emit an audit entry recording the action, the acting user (or "system"), and the affected tenant

#### Scenario: A failed audit write never breaks the audited mutation

- **GIVEN** the OpenRegister audit sink is unavailable
- **WHEN** a tenant mutation is audited
- **THEN** the mutation MUST still succeed
- **AND** the system MUST report the audit entry as not persisted rather than claiming success

#### Scenario: The hardening checklist reflects live state and fails closed

- **GIVEN** the chain-member-12 hardening checklist is compiled
- **WHEN** a control's status is reported
- **THEN** every entry MUST carry an explicit status of "pass" or "unverified"
- **AND** the audit-logging claim MUST be probed against the live audit sink rather than hardcoded
- **AND** when the audit sink is unavailable the claim MUST degrade to "unverified"
- **AND** a control with no executing verification (e.g. the isolation pen-test) MUST NOT be reported as "pass"

#### Scenario: AVG deletion confirmed on termination

- **GIVEN** a tenant is terminated
- **WHEN** the retention period lapses
- **THEN** the system SHALL confirm deletion of tenant data

