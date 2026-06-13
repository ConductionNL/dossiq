---
kind: code
depends_on: [tenant-zaaksysteem-saas-07-onboarding-workflow]
chain:
  - tenant-zaaksysteem-saas-01-schemas-and-seed
  - tenant-zaaksysteem-saas-02-tenant-crud-lifecycle
  - tenant-zaaksysteem-saas-03-schema-provisioning
  - tenant-zaaksysteem-saas-04-tenant-context-isolation
  - tenant-zaaksysteem-saas-05-auth-jwt-tenant-claim
  - tenant-zaaksysteem-saas-06-mandate-validation
  - tenant-zaaksysteem-saas-07-onboarding-workflow
  - tenant-zaaksysteem-saas-08-configuration-branding
  - tenant-zaaksysteem-saas-09-quotas-enforcement
  - tenant-zaaksysteem-saas-10-billing-shillinq
  - tenant-zaaksysteem-saas-11-suspension-termination
  - tenant-zaaksysteem-saas-12-isolation-tests-compliance
---

# Proposal: tenant-zaaksysteem-saas-08-configuration-branding

Member 8 of 12 in the **tenant-zaaksysteem-saas** chain (ADR-032). Predecessor: `tenant-zaaksysteem-saas-07-onboarding-workflow`. This `kind: code` member implements per-tenant configuration: branding/theming (CSS-variable injection over NL Design System), locale/timezone/feature-flags, and custom-domain provisioning via Let's Encrypt/ACME.

## Why

Per-tenant customisation (branding, domain, locale, feature flags) is what makes the SaaS feel like the municipality's own system — a key differentiator and an onboarding step (member 07's `branding` / `sso_setup` steps link here). Theming must inject CSS variables that NL Design System components respect (ADR-010), and custom domains need automated SSL.

## What Changes (this member)

1. `TenantConfigurationService` + `TenantConfigurationController` (GET/PATCH): branding (logo upload to Nextcloud, hex-color validation), locale/timezone/dateFormat/currency, feature flags.
2. `BrandingController.getThemingTokens()` returns a CSS-variable map (`--tenant-primary`, etc.); the frontend injects it into `<head>`; NL Design System components consume it; enterprise custom CSS is XSS-sanitised.
3. `DomainProvisioningService` + `DomainController`: domain validation, ACME (DNS TXT) challenge, Let's Encrypt cert issuance/installation, subdomain → tenant resolution.

## Impact

- **Affected**: procest (`TenantConfigurationService`, `TenantConfigurationController`, `BrandingController`, `DomainProvisioningService`, `DomainController`, theming Vue), nl-design (tokens), Nextcloud file API (logo storage), Let's Encrypt ACME.
- **Traces to giant tasks**: Task 10 (config storage + API), Task 11 (branding/theming CSS variables), Task 12 (domain provisioning), REQ-004-A/B/C/D.
- **Depends on**: member 07 (onboarding links to branding/SSO steps) + member 01 (`TenantConfiguration` schema).
