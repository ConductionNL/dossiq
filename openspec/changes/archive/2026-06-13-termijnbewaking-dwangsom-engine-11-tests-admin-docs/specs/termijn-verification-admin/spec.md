---
status: draft
---

# End-to-end verification, admin UI, and docs — Specification Delta

## ADDED Requirements

### Requirement: End-to-end termijn workflow verification (REQ-TERM-E2E-001)

The system SHALL verify the full termijn/dwangsom lifecycle end-to-end across the five canonical case paths.

#### Scenario: Five lifecycle scenarios pass end-to-end

- **GIVEN** the full chain is implemented
- **WHEN** the end-to-end suite runs the normal, pause/resume, extension, overschrijding+dwangsom, and bezwaar scenarios
- **THEN** each scenario SHALL assert the correct status transitions, event emissions, notifications, and computed amounts
- **AND** the dwangsom scenario SHALL run against mocked time and a mocked ERP callback to verify tariff transitions and payment confirmation

### Requirement: Admin TermijnDefinitie configuration UI (REQ-TERM-ADMIN-001)

The system SHALL provide an admin UI to view, create, edit, and version TermijnDefinities, with changes affecting only new cases.

#### Scenario: Admin creates and versions a TermijnDefinitie

- **GIVEN** an admin opens the TermijnDefinities tab
- **WHEN** they create or edit a definition and save
- **THEN** the definition SHALL be listed with zaaktype, wettelijkeGrondslag, duration, and validity period
- **AND** editing SHALL create a new version (`validFrom = today + 1`, prior `validUntil = today`) so existing cases retain their original definition while new cases use the latest version

### Requirement: Administrator and user documentation (REQ-TERM-DOC-001)

The system SHALL ship a Dutch admin guide and user guide for the termijnbewaking engine.

#### Scenario: Guides cover configuration and handler workflow

- **GIVEN** the feature is shipped
- **WHEN** an admin or handler consults the documentation
- **THEN** the admin guide SHALL cover TermijnDefinitie configuration, daily-scan cronjob setup, troubleshooting, and reporting
- **AND** the user guide SHALL explain AWB deadlines, pause grounds, extension requests, ingebrekestelling registration, and where to find dwangsom reports
