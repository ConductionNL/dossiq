# Design: tenant-zaaksysteem-saas-08-configuration-branding

## Scope of this member

Per-tenant configuration storage + API, branding/theming (CSS variables), and custom-domain provisioning (Let's Encrypt/ACME). The per-tenant SSO endpoint *configuration storage* lives in `TenantConfiguration` here; the SSO *authentication* is member 05.

## Declarative-first (ADR-031, ADR-001, ADR-010)

`TenantConfiguration` is a declarative OpenRegister schema (member 01); all reads/writes go through the `ObjectService`. Theming follows ADR-010: branding values map to CSS variables consumed by standard NC / NL Design System components — no hardcoded colors. Domain provisioning (ACME, cert install) is genuine infrastructure glue — `kind: code` with no declarative analogue.

## Service layer

### TenantConfigurationService
- `getConfig(tenantId)`, `updateBranding(...)`, `updateLocale(...)`, `setFeatureFlag(...)`.
- Logo upload → stored as a Nextcloud file with a tenant-scoped file ID; returns URL.
- Hex-color validation; locale/timezone validation against a known list.

### BrandingController.getThemingTokens(tenantId)
- Builds `{ "--tenant-primary": ..., "--tenant-secondary": ..., "--tenant-logo-url": ..., "--tenant-font-family": ... }` from branding.
- Frontend injects it as a `<style>:root{...}</style>` block; NL Design System components respect the variables.
- Enterprise tier may add custom CSS, sanitised via a CSS-property whitelist (XSS-safe).

### DomainProvisioningService / DomainController
- `validateDomain()`, `requestACME()` (DNS TXT challenge), `installCertificate()`.
- Let's Encrypt integration; cert installed in the reverse proxy.
- Subdomain → tenant resolution feeds `TenantContextMiddleware` (member 04).

## API routes (ADR-016)

```
GET   /api/tenants/{tenantId}/config                 → show()
PATCH /api/tenants/{tenantId}/config                 → update()
POST  /api/tenants/{tenantId}/config/branding        → BrandingController.update()
GET   /api/tenants/{tenantId}/config/theming-tokens  → BrandingController.getTokens()
POST  /api/tenants/{tenantId}/config/domain          → DomainController.provision()
```

## Security (ADR-005, ADR-010)

Config endpoints are tenant-admin scoped (mandate stack, member 06). Logo upload validates MIME + size (≤5MB, PNG/SVG). Enterprise custom CSS is the highest-risk surface: a property/value whitelist parser blocks `expression()`, `url(javascript:...)`, and `<script>` injection (XSS). Color inputs validated as hex. Theming uses CSS variables only — no inline hardcoded colors (ADR-010). Domain provisioning validates ownership before issuing a cert.

## Tests

- Integration: config CRUD; logo upload + URL generation; feature-flag storage/retrieval.
- Integration: theming-tokens API returns the CSS-variable map; tokens inject on page load.
- Unit: hex-color + locale validation; custom-CSS XSS sanitiser rejects malicious rules.
- Integration: domain provisioning workflow (mockable without live DNS); ACME error handling.
