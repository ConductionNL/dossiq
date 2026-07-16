# tenant-compliance delta — compliance-and-tenant-fixes

## MODIFIED Requirements

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
