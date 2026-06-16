---
status: draft
---

# Tenant configuration and branding — Specification Delta

## ADDED Requirements

### Requirement: Branding configuration and theming tokens (REQ-004-A)

The system SHALL persist per-tenant branding and serve CSS-variable theming tokens that NL Design System components respect.

#### Scenario: Branding saved and validated

- **GIVEN** a tenant admin uploads a logo (PNG/SVG ≤5MB) and selects primary #0066CC, secondary #FF6600
- **WHEN** saved
- **THEN** the logo SHALL be stored as a Nextcloud file with a tenant-scoped file ID
- **AND** `TenantConfiguration.branding` SHALL be updated with the logo reference and validated hex colors

#### Scenario: Theming tokens injected

- **GIVEN** branding is configured
- **WHEN** the frontend requests `GET /api/tenants/{tenantId}/config/theming-tokens`
- **THEN** the response SHALL be a CSS-variable map including `--tenant-primary`, `--tenant-secondary`, and `--tenant-logo-url`
- **AND** the frontend SHALL inject it into `<head>` so NL Design System components use the tenant colors
- **AND** enterprise custom CSS SHALL be XSS-sanitised via a property whitelist

### Requirement: Locale, timezone, and feature flags (REQ-004-C, REQ-004-D)

The system SHALL store per-tenant locale/timezone/date settings and feature flags taking immediate effect.

#### Scenario: Locale applied

- **GIVEN** a tenant sets locale="nl_NL", timezone="Europe/Amsterdam", dateFormat="DD-MM-YYYY"
- **WHEN** saved and pages render
- **THEN** timestamps SHALL render in Europe/Amsterdam and dates as DD-MM-YYYY

#### Scenario: Feature flag toggles UI immediately

- **GIVEN** a tenant admin enables "workflow_engine_beta"
- **WHEN** the flag is saved
- **THEN** `TenantConfiguration.features` SHALL include it and the beta UI SHALL appear without a cache-invalidation wait

### Requirement: Custom domain provisioning with Let's Encrypt (REQ-004-B)

The system SHALL provision a custom domain with an automated ACME-issued certificate and resolve the subdomain to its tenant.

#### Scenario: Domain provisioned and resolves to tenant

- **GIVEN** a tenant admin enters "groningen.zaaksysteem.nl"
- **WHEN** provisioning is requested
- **THEN** domain ownership SHALL be validated via an ACME DNS challenge and a Let's Encrypt certificate issued and installed
- **AND** subsequent requests to that subdomain SHALL resolve to the tenant's context and branding
