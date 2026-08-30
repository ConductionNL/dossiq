# Spec delta: archief-edepot-handover-08-admin-ui-docs

## ADDED Requirements

### Requirement: DIV admin can manage retention rules through a validated UI
The system MUST let a DIV admin view, create, edit and delete `BewaarTermijnRegel` entries with validation, in Dutch and English.

#### Scenario: Create and edit a retention rule
- **GIVEN** a DIV admin on the retention-rule configuration screen
- **WHEN** they create a rule with zaaktypeKey, bewaartermijnJaren, selectielijstCategorie, eDepotBestemming and mdtoVersion
- **THEN** the rule is persisted and listed
- **AND** validation rejects a `bewaartermijnJaren` that is neither ≥ 1 nor "permanent"
- **AND** the admin can subsequently edit or delete the rule
- **AND** all visible labels are available in Dutch and English

### Requirement: DIV can monitor archival status on a dashboard
The system MUST present archival status (ready, in-progress, failed, completed, total transferred) and batch jobs with quick actions.

#### Scenario: Dashboard reflects current state
- **GIVEN** triggers and batch jobs in various states
- **WHEN** DIV opens the archival dashboard (`GET /api/archief/dashboard/stats`)
- **THEN** stat cards show counts for ready-for-transfer, in-progress, failed, completed and total transferred
- **AND** a triggers table and a batch-jobs table are shown with quick actions to initiate a batch, retry failed cases and view proof

### Requirement: The capability is covered by tests and operator documentation
The system MUST ship unit and end-to-end tests for the archival pipeline and admin/developer/e-Depot documentation.

#### Scenario: End-to-end workflow is tested and documented
- **GIVEN** the archival pipeline is implemented across the chain
- **WHEN** the test suite runs
- **THEN** an end-to-end happy-path test (trigger → bundle → submit → proof) and a failure-path test (failure → DIV notified → corrected → retry succeeds) both pass
- **AND** an admin guide, a developer guide and an e-Depot integration guide are present and describe setup, batch processing, failure handling and SIP/openconnector configuration
