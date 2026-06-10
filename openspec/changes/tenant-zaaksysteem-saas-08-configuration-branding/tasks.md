# Tasks: tenant-zaaksysteem-saas-08-configuration-branding

> **Build status (hydra audit).** The basic TenantService + TenantMiddleware + TenantController shipped via the sibling 'migrate-tenant-to-or-tenant' change (delegates to OR's TenantLifecycleService). This 12-member SaaS chain layers on the full SaaS shape — Tenant/TenantConfiguration/TenantQuota/TenantUser schemas, schema-per-tenant provisioning, JWT tenant-claim auth, mandate validation, onboarding workflow, branding, quota enforcement, shillinq billing, suspension/termination, isolation tests — none of which exist on dev yet. Tasks stay [ ] as genuine forward work.

Member 8 of 12 (code). Depends on member 07. Traces to giant Task 10 + Task 11 + Task 12 + REQ-004.

## 1. Configuration storage + API

- [ ] Implement `TenantConfigurationService` (getConfig, updateBranding, updateLocale, setFeatureFlag)
- [ ] Implement `TenantConfigurationController` (GET, PATCH) tenant-admin scoped
- [ ] Add logo upload handler (store in Nextcloud, generate URL, validate MIME + ≤5MB)
- [ ] Add hex-color + locale/timezone validation
- [ ] Store + retrieve feature flags (immediate effect)

## 2. Branding / theming

- [ ] Implement `BrandingController.getThemingTokens()` (CSS-variable map from branding)
- [ ] Frontend injects tokens into `<head>` as a `<style>` block (i18n nl+en)
- [ ] NL Design System components consume the CSS variables (no hardcoded colors, ADR-010)
- [ ] Sanitise enterprise custom CSS via a property whitelist (XSS-safe)

## 3. Domain provisioning + tests

- [ ] Implement `DomainProvisioningService` (validateDomain, requestACME DNS challenge, installCertificate)
- [ ] Let's Encrypt integration + cert install in reverse proxy
- [ ] Subdomain → tenant resolution feeding the context middleware
- [ ] Integration test: config CRUD + logo upload + feature flags
- [ ] Integration test: theming-tokens API + injection; custom-CSS sanitiser rejects malicious rules
- [ ] Integration test: domain provisioning workflow (mockable) + ACME error handling
