---
status: draft
---

# Tenant billing and Shillinq export — Specification Delta

## ADDED Requirements

### Requirement: Billing event emission on case lifecycle (REQ-007-A)

The system SHALL emit immutable billing events on the case lifecycle.

#### Scenario: Case-created event emitted

- **GIVEN** a tenant on a pay-per-case model
- **WHEN** a case is created successfully
- **THEN** a `TenantBillingEvent` SHALL be inserted (eventType="case_created", quantity=1, unitPrice, currency=EUR, occurredAt=now, invoiceRef=NULL)

#### Scenario: Refund nets prior charge

- **GIVEN** a case is withdrawn/deleted before closure
- **WHEN** `refund()` is called
- **THEN** a billing event SHALL be emitted (eventType="case_refund", quantity=-1) to net against the prior case_created charge

### Requirement: Daily billing export to Shillinq (REQ-007-B)

The system SHALL export pending billing events to Shillinq daily, grouping by tenant-month, with retry and deferral on failure.

#### Scenario: Export sets invoiceRef on success

- **GIVEN** events with invoiceRef=NULL exist
- **WHEN** the nightly export job runs
- **THEN** events SHALL be grouped by tenant and month and POSTed to the Shillinq invoices API
- **AND** on success the events for that tenant-month SHALL be updated with the returned invoiceRef
- **AND** the job SHALL be idempotent (only invoiceRef-NULL rows are touched, no double-invoicing)

#### Scenario: Export retries then defers on failure

- **GIVEN** the Shillinq API is unavailable
- **WHEN** the export job runs
- **THEN** the call SHALL be retried up to 3 times with exponential backoff
- **AND** if still failing, processing SHALL defer to the next day and alert ops

### Requirement: Tenant billing dashboard (REQ-007-C)

The system SHALL present a tenant billing dashboard with current-month, YTD, forecast, invoices, and quota status.

#### Scenario: Dashboard renders billing summary

- **GIVEN** a tenant admin opens the billing dashboard
- **WHEN** the page loads
- **THEN** it SHALL display the current-month summary (cases, refunds, total), YTD breakdown, forecasted spend, invoice history with download links, and quota status
