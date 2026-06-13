# tenant-auth Specification

## Purpose
TBD - created by archiving change tenant-zaaksysteem-saas-05-auth-jwt-tenant-claim. Update Purpose after archive.
## Requirements
### Requirement: JWT tenant claim injection (REQ-006-B)

The system SHALL inject the tenant context into the JWT at login, including via eHerkenning SAML, and SHALL sign the token with the Procest key.

#### Scenario: eHerkenning login issues tenant-scoped JWT

- **GIVEN** a user logs in via eHerkenning for tenant A with eherkenning_level=3, roles=[Behandelaar]
- **WHEN** `AuthenticationService.createTokenFromSAML()` runs
- **THEN** the issued JWT SHALL include `tenant_id` (tenant A), `tenant_slug`, mapped `roles`, and `eherkenning_level`
- **AND** the JWT SHALL be signed with the Procest private key and returned to the browser

### Requirement: JWT signature validation and tenant extraction (REQ-002-B)

The system SHALL validate the JWT signature and extract tenant_id before trusting any claim, rejecting forged tokens.

#### Scenario: Forged JWT rejected

- **GIVEN** an attacker presents a JWT claiming `tenant_id="A"` without a valid signature
- **WHEN** `AuthenticateMiddleware` processes the request
- **THEN** the request SHALL be rejected with HTTP 401 Unauthorized
- **AND** the forgery attempt SHALL be logged as a security incident

#### Scenario: Valid token populates tenant context

- **GIVEN** a JWT with a valid signature and `tenant_id="A"`
- **WHEN** the middleware processes it
- **THEN** tenant_id="A" SHALL be handed to the request-scoped tenant context for downstream isolation

### Requirement: Cross-tenant token rejection and alerting (REQ-002-C, REQ-006-C)

The system SHALL reject a valid tenant-A token used against tenant B, log the attempt, and alert after repeated failures.

#### Scenario: Cross-tenant token blocked and logged

- **GIVEN** a user holds a valid JWT for tenant A
- **WHEN** they use it against `tenantb.zaaksysteem.nl` resources
- **THEN** `TenantClaimValidationMiddleware` SHALL detect the mismatch and respond HTTP 403 Forbidden
- **AND** the attempt SHALL be logged with IP, timestamp, attempted tenant_id, and user
- **AND** after more than 5 such failures within 1 hour from the same IP, the security team SHALL be alerted

