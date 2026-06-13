---
status: done
status-note: Reverse-synced 2026-06-13 from an archived fully-implemented change; capability code confirmed present on development.
---
# tenant-onboarding Specification

## Purpose
TBD - created by archiving change tenant-zaaksysteem-saas-07-onboarding-workflow. Update Purpose after archive.
## Requirements
### Requirement: Onboarding checklist and progress dashboard (REQ-003-A, REQ-003-D)

The system SHALL initialise a 7-step onboarding checklist per new tenant and render a progress dashboard.

#### Scenario: Checklist initialised

- **GIVEN** a new tenant is created with status="onboarding"
- **WHEN** `createOnboarding(tenantId)` runs
- **THEN** a checklist SHALL be created with steps {contract, mandate_import, sso_setup, branding, zaaktype_selection, first_user, go_live}, all "pending"
- **AND** the tenant admin SHALL receive an email with the checklist link

#### Scenario: Progress dashboard reflects status

- **GIVEN** a tenant admin opens the onboarding dashboard
- **WHEN** the page renders
- **THEN** it SHALL show X/7 completed with per-step status badges and timestamps for completed steps
- **AND** the next recommended step SHALL be highlighted with a call-to-action

### Requirement: Contract signature via Decidesk (REQ-003-B)

The system SHALL integrate Decidesk for contract e-signature and complete the contract step via a signature-verified webhook.

#### Scenario: Contract signed updates tenant

- **GIVEN** the tenant admin signs the contract in Decidesk
- **WHEN** Decidesk calls `POST /webhooks/decidesk/contract-signed` with a verified signature
- **THEN** `Tenant.contractRef` SHALL be set to the Decidesk contract ID
- **AND** the "contract" onboarding step SHALL be marked completed
- **AND** an unverified webhook payload SHALL be rejected without mutating tenant state

### Requirement: Go-live validation and activation (REQ-003-C)

The system SHALL validate mandatory prerequisites before activating a tenant.

#### Scenario: Go-live blocked on missing prerequisites

- **GIVEN** a tenant lacks a tenant_admin user
- **WHEN** go-live is requested
- **THEN** `validateGoLive` SHALL fail and list the missing prerequisites (≥1 zaaktype, ≥1 mandaat, ≥1 tenant_admin)

#### Scenario: Go-live activates tenant

- **GIVEN** all prerequisites are met
- **WHEN** the admin confirms go-live
- **THEN** status SHALL transition onboarding → active, activatedAt SHALL be set, and quota initialisation SHALL be triggered

