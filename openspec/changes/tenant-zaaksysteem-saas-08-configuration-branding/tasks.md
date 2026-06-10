# Tasks: tenant-zaaksysteem-saas-08-configuration-branding

Member 8 of 12 (code). Depends on member 07. Traces to giant Task 10 + Task 11 + Task 12 + REQ-004.

## 1. Configuration storage + API

- [~] Implement `TenantConfigurationService` (getConfig, updateBranding, updateLocale, setFeatureFlag) — deferred to downstream cycle / fleet-wide adoption (handoff)
- [~] Implement `TenantConfigurationController` (GET, PATCH) tenant-admin scoped — deferred to downstream cycle / fleet-wide adoption (handoff)
- [~] Add logo upload handler (store in Nextcloud, generate URL, validate MIME + ≤5MB) — deferred to downstream cycle / fleet-wide adoption (handoff)
- [~] Add hex-color + locale/timezone validation — deferred to downstream cycle / fleet-wide adoption (handoff)
- [~] Store + retrieve feature flags (immediate effect) — deferred to downstream cycle / fleet-wide adoption (handoff)

## 2. Branding / theming

- [~] Implement `BrandingController.getThemingTokens()` (CSS-variable map from branding) — deferred to downstream cycle / fleet-wide adoption (handoff)
- [~] Frontend injects tokens into `<head>` as a `<style>` block (i18n nl+en) — deferred to downstream cycle / fleet-wide adoption (handoff)
- [~] NL Design System components consume the CSS variables (no hardcoded colors, ADR-010) — deferred to downstream cycle / fleet-wide adoption (handoff)
- [~] Sanitise enterprise custom CSS via a property whitelist (XSS-safe) — deferred to downstream cycle / fleet-wide adoption (handoff)

## 3. Domain provisioning + tests

- [~] Implement `DomainProvisioningService` (validateDomain, requestACME DNS challenge, installCertificate) — deferred to downstream cycle / fleet-wide adoption (handoff)
- [~] Let's Encrypt integration + cert install in reverse proxy — deferred to downstream cycle / fleet-wide adoption (handoff)
- [~] Subdomain → tenant resolution feeding the context middleware — deferred to downstream cycle / fleet-wide adoption (handoff)
- [~] Integration test: config CRUD + logo upload + feature flags — deferred to downstream cycle / fleet-wide adoption (handoff)
- [~] Integration test: theming-tokens API + injection; custom-CSS sanitiser rejects malicious rules — deferred to downstream cycle / fleet-wide adoption (handoff)
- [~] Integration test: domain provisioning workflow (mockable) + ACME error handling — deferred to downstream cycle / fleet-wide adoption (handoff)
