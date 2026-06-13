# Design: tenant-zaaksysteem-saas-05-auth-jwt-tenant-claim

## Scope of this member

JWT tenant-claim injection at login + per-request claim validation. The per-tenant IdP endpoint configuration (SAML/OIDC metadata) is member 08; mandate-matrix checks are member 06.

## Declarative-first (ADR-031, ADR-001)

Authentication / JWT handling is imperative security plumbing with no declarative analogue — `kind: code` per ADR-032. The `Tenant` and `TenantUser` records it reads (to resolve roles + tenant) are the OpenRegister objects from member 01.

## Components

### AuthenticationService (enhanced)
- Accepts tenant context during login.
- Injects `tenant_id`, `tenant_slug`, `roles`, and (for eHerkenning) `eherkenning_level` into the JWT payload.
- `createTokenFromSAML()` maps an eHerkenning SAML assertion (role, level) into the tenant-scoped JWT.
- Signs the JWT with the Procest private key.

### AuthenticateMiddleware (enhanced)
- Validates the JWT signature against the configured key; expired/invalid → 401.
- Extracts tenant_id and hands it to `TenantContext` (member 04) for downstream middleware.
- Logs JWT forgery/modification attempts as security incidents.

### TenantClaimValidationMiddleware
- Extracts the request tenant from URL subdomain / header.
- Compares it to the JWT tenant_id; mismatch → 403 Forbidden.
- Logs cross-tenant attempts (IP, timestamp, attempted tenant_id, user).
- Tracks failed attempts per IP; alerts the security team after 5 failures in 1 hour.

## JWT structure

```json
{ "sub": "...", "email": "...", "tenant_id": "...", "tenant_slug": "...",
  "roles": ["case_handler"], "eherkenning_level": 3, "iat": ..., "exp": ... }
```

## Security (ADR-005)

This member is a security-critical control. Signature validation is mandatory before trusting any claim (a forged tenant_id without a valid signature → 401). The 403-on-mismatch + audit-log + rate-limit-alert chain implements REQ-002-C. No plaintext secrets: the signing key is read from secure config, never logged. The rate-limit counter must fail closed — if the counter store is unavailable, treat as over-limit rather than skipping the check.

## Tests

- Unit: JWT creation with tenant claims; SAML → JWT mapping.
- Unit: JWT validation with tenant claims; forged-signature rejection (401).
- Integration: cross-tenant token rejection (403) + security log entry.
- Integration: rate-limit alert fires after N failed attempts.
