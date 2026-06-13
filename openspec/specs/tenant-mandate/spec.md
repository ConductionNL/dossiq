---
status: done
status-note: Reverse-synced 2026-06-13 from an archived fully-implemented change; capability code confirmed present on development.
---
# tenant-mandate Specification

## Purpose
TBD - created by archiving change tenant-zaaksysteem-saas-06-mandate-validation. Update Purpose after archive.
## Requirements
### Requirement: Mandate matrix validation per action (REQ-002-D, REQ-006-D)

The system SHALL check a user's tenant role against the tenant's mandaat-matrix for each mandate-requiring action, allowing or denying with an audit-logged result, and SHALL fail closed when the matrix cannot be resolved.

#### Scenario: Authorised action proceeds

- **GIVEN** User-A has eHerkenning role "case_handler" on tenant A
- **WHEN** they attempt to update a case (PATCH /api/cases/{id})
- **THEN** `validateMandateMatrix(tenant_A, user_id, "case_edit")` SHALL be called
- **AND** if the matrix grants the role this action, the update SHALL proceed
- **AND** the decision SHALL be recorded in the audit trail

#### Scenario: Unauthorised action blocked

- **GIVEN** User-A has role "Behandelaar" without status-transition rights
- **WHEN** they attempt to transition a case from "In behandeling" → "Beschikking opgesteld"
- **THEN** the mandate check SHALL deny the action
- **AND** the request SHALL be blocked with HTTP 403 Forbidden and a reason ("Your role does not have permission to update case status. Contact your administrator.")
- **AND** the denial SHALL be recorded in the audit trail

#### Scenario: Fail closed on unresolved matrix

- **GIVEN** a tenant has no mandaat-matrix configured or the mandate service errors
- **WHEN** a mandate-requiring action is attempted
- **THEN** the action SHALL be denied (fail closed), not allowed

