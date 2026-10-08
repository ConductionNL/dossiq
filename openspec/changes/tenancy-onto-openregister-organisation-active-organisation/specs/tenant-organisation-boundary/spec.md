## ADDED Requirements

### Requirement: No token decides or checks the tenant (REQ-TAO-001)

Decided by Ruben on 2026-10-08 (Q2). dossiq SHALL NOT ship `TenantJwtService`,
`TenantClaimValidationMiddleware` or `TenantClaimMismatchException`, and the hardening checklist
SHALL NOT cite them. The membership, role and mandate lookups in `TenantAuthenticationService` SHALL
stay until they move to OpenRegister.

#### Scenario: The token path is gone and the secret stays
- **GIVEN** the codebase after this change
- **WHEN** `lib/` and the registrars are searched
- **THEN** none of the three classes SHALL be named
- **AND** `PortalAssertionVerifier` SHALL still read `jwt_signing_secret`

### Requirement: The active tenant is OpenRegister's active organisation (REQ-TAO-002)

Decided by Ruben on 2026-10-08 (Q3). The tenant a request acts as SHALL be the user's active
organisation as `OrganisationService::getActiveOrganisation()` answers it, and only when the user's
`tenantUser` memberships list it. dossiq SHALL keep no tenant choice of its own in the session and
SHALL route no tenant switch; switching is OpenRegister's `set-active`.

#### Scenario: The mandate check follows the organisation set active in OpenRegister
- **GIVEN** a user who belongs to organisations A and B, with a `tenantUser` row in each, and B set active through OpenRegister
- **WHEN** the user makes a dossiq write that the mandate matrix checks
- **THEN** `MandateValidationMiddleware` SHALL check it against B's matrix

#### Scenario: An active organisation without a dossiq membership is no tenant
- **GIVEN** a user whose active organisation in OpenRegister is C, with no `tenantUser` row for C
- **WHEN** dossiq resolves the request's tenant
- **THEN** the request SHALL have no tenant

### Requirement: A request for an organisation that is not active is refused on dossiq's routes (REQ-TAO-003)

For a signed-in user who is not a platform admin, on a controller outside the exempt list, a request
whose bound organisation's status is not `active` SHALL be refused with HTTP 403. A user with no
organisation SHALL be let through, as on a single-tenant install.

#### Scenario: A suspended organisation cannot work in dossiq
- **GIVEN** a member of organisation A, and A suspended in OpenRegister
- **WHEN** the member calls a dossiq case route
- **THEN** the answer SHALL be HTTP 403

#### Scenario: A platform admin is not refused
- **GIVEN** a platform admin whose active organisation is suspended
- **WHEN** the admin calls the same route
- **THEN** the request SHALL be let through

### Requirement: ADR-004 records Q2 and Q3 (REQ-TAO-004)

`openspec/architecture/adr-004-tenant-cluster-adr-022-exception.md` SHALL cover
`TenantAuthenticationService` for rule 4 with the reason that its lookups stay until they move to
OpenRegister, and SHALL name none of the five classes this change deletes.

#### Scenario: No tenant boundary finding is left
- **GIVEN** this change and `tenant-isolation-names-the-control-that-runs` merged on `development`
- **WHEN** gate 23 runs with `--base origin/development`
- **THEN** it SHALL report no `or-capability:tenant-boundary` finding
- **AND** `TenantAuthenticationService.php` SHALL be printed as suppressed for rule 4 only
