# tenant-schemas Specification

## Purpose
TBD - created by archiving change tenant-zaaksysteem-saas-01-schemas-and-seed. Update Purpose after archive.
## Requirements
### Requirement: Multi-tenant register schemas (REQ-001-A-SCHEMA)

The system SHALL declare seven OpenRegister schemas — `Tenant`, `TenantConfiguration`, `TenantQuota`, `TenantUser`, `TenantMandate`, `TenantBillingEvent`, and `TenantOnboardingTask` — with the documented properties, enums, and relations, registered through the procest register template so every consumer reads the same canonical shape.

#### Scenario: Schemas materialise with documented properties

- **GIVEN** the procest register template is imported into OpenRegister
- **WHEN** the seven tenant schemas are materialised
- **THEN** the `Tenant` schema SHALL expose `slug` (unique), `displayName`, a `status` enum of {onboarding, active, suspended, terminated}, a `tier` enum of {basic, standard, enterprise}, an `isolationMode` enum of {schema, database}, and a `dataResidency` enum of {nl, eu}
- **AND** `TenantBillingEvent` SHALL be modelled as an insert-only schema with an `eventType` enum including {case_created, case_closed, user_activated, quota_exceeded, case_refund}

#### Scenario: Relations between schemas are declared

- **GIVEN** the schemas are registered
- **WHEN** the relations are inspected
- **THEN** `Tenant` SHALL relate one-to-one to `TenantConfiguration`, and one-to-many to `TenantQuota`, `TenantUser`, `TenantMandate`, `TenantBillingEvent`, and `TenantOnboardingTask`
- **AND** each `TenantQuota` row SHALL carry a `quotaType` enum of {cases_per_month, storage_gb, active_users, api_calls_per_hour} and an `enforcement` enum of {warn, throttle, block}

### Requirement: Seed tier templates and default-tenant onboarding template (REQ-001-B-SEED)

The system SHALL seed tier quota-limit templates (basic, standard, enterprise) and a default-tenant onboarding template via the OpenRegister repair-step import so later members can fork working configuration out of the box.

#### Scenario: Seed templates load via repair step

- **GIVEN** the procest app is enabled and the register repair step runs
- **WHEN** the seed import completes
- **THEN** the tier quota templates SHALL be queryable via the OpenRegister REST API (basic = cases_per_month 100, standard = 1000, enterprise = unlimited)
- **AND** the default-tenant onboarding template SHALL declare the seven steps {contract, mandate_import, sso_setup, branding, zaaktype_selection, first_user, go_live} in `pending` state

### Requirement: Tenant-scoped OpenRegister query materialisation (REQ-009-SCHEMA)

The system SHALL ensure OpenRegister queries are tenant-scoped at materialisation so a query carrying a tenant context returns only that tenant's rows; no schema prefixing is required in queries.

#### Scenario: Integration test verifies materialised fields and scoping

- **GIVEN** the schemas and seed data are imported
- **WHEN** the integration test queries each schema and the seed rows
- **THEN** the test SHALL assert the documented required properties are present on each of the seven schemas
- **AND** the test SHALL assert the tier templates and the default-tenant onboarding template exist
- **AND** the test SHALL assert that a query carrying tenant context A returns only tenant A rows and never tenant B rows

