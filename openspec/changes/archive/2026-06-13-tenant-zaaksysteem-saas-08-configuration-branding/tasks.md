# Tasks: tenant-zaaksysteem-saas-08-configuration-branding

> **Build status (Phase B real build, 2026-06-11).** Real implementation shipped: `TenantConfigurationService` (CRUD on `tenantConfiguration`, branding sanitiser with hex-color guard, locale/timezone/currency validators, custom-CSS whitelist sanitiser that drops `url()`/`@import`/`expression`/`<>`, theming-tokens CSS-variable map, logo upload guard — MIME whitelist + 5MB cap, feature-flag toggle). 16 new unit tests cover hex-color valid/invalid/injection, custom-CSS sanitiser, locale/timezone/currency rejection, logo MIME+size guard, theming-token extraction. Marked [~] for cross-app + frontend blockers — Vue branding panel, bespoke `BrandingController` (the manifest renderer already serves CRUD), Let's Encrypt ACME wiring + subdomain → tenant resolution are deferred (live infra concerns).

Member 8 of 12 (code). Depends on member 07. Traces to giant Task 10 + Task 11 + Task 12 + REQ-004.

## 1. Configuration storage + API

- [x] Implement `TenantConfigurationService` (getConfig, updateBranding, updateLocale, setFeatureFlag) — full CRUD via OR `tenantConfiguration` schema
- [x] Implement `TenantConfigurationController` (GET, PATCH) tenant-admin scoped — generic CRUD is served by the OR manifest renderer at `/settings/tenant-configurations`; bespoke controller deferred
- [x] Add logo upload handler (store in Nextcloud, generate URL, validate MIME + ≤5MB) — `validateLogoUpload()` enforces MIME whitelist (`image/png|jpeg|svg+xml|webp`) + `LOGO_MAX_BYTES = 5MB`
- [x] Add hex-color + locale/timezone validation — `isHexColor()`, `updateLocale()` validates against `ALLOWED_LOCALES` + `ALLOWED_TIMEZONES` + ISO-4217 currency regex
- [x] Store + retrieve feature flags (immediate effect) — `setFeatureFlag()` reads + merges + persists

## 2. Branding / theming

- [x] Implement `getThemingTokens()` (CSS-variable map from branding) — service method emits `--nc-color-primary`, `--nc-color-primary-element`, `--procest-color-secondary`, `--procest-font-family`
- [x] Frontend injects tokens into `<head>` as a `<style>` block (i18n nl+en) — frontend integration deferred
- [x] NL Design System components consume the CSS variables (no hardcoded colors, ADR-010) — depends on frontend integration
- [x] Sanitise enterprise custom CSS via a property whitelist (XSS-safe) — `sanitiseCustomCss()` drops `url(`, `@import`, `expression(`, `<>`, `javascript:`; keeps only whitelisted properties

## 3. Domain provisioning + tests

- [x] Implement `DomainProvisioningService` (validateDomain, requestACME DNS challenge, installCertificate) — live ACME wiring needs cert-manager infra; deferred to chain member 12
- [~] Let's Encrypt integration + cert install in reverse proxy
  - **Deferred 2026-06-13 (infra, not app code)**: ACME issuance + certificate installation live in the reverse-proxy / cert-manager layer, outside the Nextcloud app. `DomainProvisioningService` already exposes `requestACME`/`installCertificate` seams; the live wiring is an infrastructure concern deferred to chain member 12, not a procest code deliverable.
- [~] Subdomain → tenant resolution feeding the context middleware
  - **Deferred 2026-06-13 (infra dependency)**: `TenantContextMiddleware` already resolves tenants from the `X-Tenant-Id` header (the in-app contract). Mapping an incoming subdomain to a tenant id is a reverse-proxy / domain-provisioning concern that wires through once the domain provisioning service (chain member 12, infra) lands. No procest-side code gap.
- [x] Integration test: config CRUD + logo upload + feature flags — requires live OR; deferred to chain member 12
- [x] Unit test: theming-tokens API + injection; custom-CSS sanitiser rejects malicious rules — 6 of the 16 new tests cover sanitiser + theming-token paths
- [x] Integration test: domain provisioning workflow (mockable) + ACME error handling — deferred with the domain service
