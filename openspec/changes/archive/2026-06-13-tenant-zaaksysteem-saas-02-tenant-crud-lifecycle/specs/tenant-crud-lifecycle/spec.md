---
status: draft
---

# Tenant CRUD and lifecycle — Specification Delta

## ADDED Requirements

### Requirement: Tenant creation with unique slug (REQ-001-A)

The system SHALL create a `Tenant` with an auto-generated unique slug and an initial `onboarding` status, rejecting duplicate slugs.

#### Scenario: Tenant created with generated slug

- **GIVEN** a SaaS administrator submits name="Gemeente Groningen", kvkNumber="34251000", tier="standard"
- **WHEN** the create endpoint is called
- **THEN** a `Tenant` record SHALL be created with slug="gemeente-groningen" (lowercased, hyphens, max 64 chars)
- **AND** status SHALL be "onboarding", createdAt SHALL be now, activatedAt SHALL be NULL, contractRef SHALL be NULL
- **AND** isolationMode SHALL be set from the tier (schema for basic/standard, optional database for enterprise)

#### Scenario: Duplicate slug rejected

- **GIVEN** a tenant with slug="gemeente-groningen" already exists
- **WHEN** a second create request would produce the same slug
- **THEN** creation SHALL fail with error "Slug already exists"

### Requirement: Tenant CRUD API (REQ-001-A-API)

The system SHALL expose admin-only CRUD endpoints for tenants and a list endpoint filterable by status.

#### Scenario: CRUD endpoints return tenant metadata

- **GIVEN** a tenant exists
- **WHEN** the GET endpoint is called by a SaaS administrator
- **THEN** the response SHALL include all tenant metadata (slug, displayName, tier, status, timestamps)
- **AND** the list endpoint SHALL support filtering by status (onboarding/active/suspended/terminated)
- **AND** a non-administrator SHALL be denied access to the tenant CRUD endpoints

### Requirement: Tenant lifecycle state machine (REQ-001-A-LIFECYCLE)

The system SHALL enforce a lifecycle state machine on `Tenant.status` so only legal transitions are persisted.

#### Scenario: Legal and illegal transitions

- **GIVEN** a tenant with status="onboarding"
- **WHEN** `updateStatus` transitions it to "active"
- **THEN** the transition SHALL be persisted
- **AND** an attempt to transition directly from "terminated" back to "active" SHALL be rejected as an illegal transition
