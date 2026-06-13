---
status: done
status-note: Reverse-synced 2026-06-13 from an archived fully-implemented change; capability code confirmed present on development.
---
# tenant-lifecycle Specification

## Purpose
TBD - created by archiving change tenant-zaaksysteem-saas-11-suspension-termination. Update Purpose after archive.
## Requirements
### Requirement: Tenant suspension and reactivation (REQ-008-A)

The system SHALL suspend and reactivate tenants, gating access and pausing billing during suspension and notifying Shillinq.

#### Scenario: Suspension blocks new cases

- **GIVEN** a SaaS provider approves a tenant's suspension request
- **WHEN** `suspend(tenantId, reason)` runs
- **THEN** `Tenant.status` SHALL be "suspended" and case-creation/API requests SHALL return HTTP 403 "Tenant is suspended"
- **AND** existing cases SHALL remain visible and no new billing events SHALL be emitted
- **AND** a Shillinq webhook `{tenant_id, status:"suspended", effective_date}` SHALL be sent

#### Scenario: Reactivation restores service

- **GIVEN** a suspended tenant is reactivated
- **WHEN** `reactivate(tenantId)` runs
- **THEN** status SHALL be "active", case creation SHALL be restored, billing SHALL resume, and a reactivation webhook SHALL be sent

### Requirement: Tenant termination and data archival (REQ-008-B)

The system SHALL terminate tenants with final billing settlement, access revocation, and retention-bounded data archival.

#### Scenario: Termination settles billing and revokes access

- **GIVEN** a tenant contract ends
- **WHEN** `terminate(tenantId, reason, retentionYears=1)` runs
- **THEN** status SHALL be "terminated", terminatedAt SHALL be set, all pending billing SHALL be finalised with invoiceRef, and a Shillinq termination webhook with the final invoice SHALL be sent
- **AND** all API access SHALL be revoked (JWT validation rejects this tenant; requests return HTTP 403 "This tenant is no longer active.")

#### Scenario: Schema archived then deleted after retention

- **GIVEN** a terminated tenant
- **WHEN** the archival job runs
- **THEN** basic/standard schemas SHALL be retained offline for the retention period and enterprise schemas exported to cold storage per dataResidency
- **AND** after the retention period the schema SHALL be deleted and an immutable deletion-confirmation log entry SHALL be written

