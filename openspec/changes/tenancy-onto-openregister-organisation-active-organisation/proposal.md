---
kind: code
depends_on: [tenant-isolation-names-the-control-that-runs]
---

# Proposal: tenancy-onto-openregister-organisation-active-organisation

Second part of the `tenancy-onto-openregister-organisation` chain. Gate 23 prerequisite, built
before the Woo changes (Ruben, 2026-10-08).

## Summary

dossiq's active tenant becomes OpenRegister's active organisation, and the token path that no longer binds anything is deleted: five `Tenant*` classes go, and the one refusal they made that OpenRegister does not make on dossiq's routes moves into `MandateValidationMiddleware`.

- Gate 23 (`or-abstraction-anti-patterns`): clears rule 7 `or-capability:tenant-boundary` by name (the last two `Tenant*Middleware*.php` files after the isolation change, plus `TenantClaimValidationMiddleware`) and removes five rule 4 files. `TenantAuthenticationService` is brought under ADR-004 for rule 4.
- Decisions: Ruben 2026-10-08, Q2 (delete `TenantJwtService`, `TenantClaimValidationMiddleware` and `TenantClaimMismatchException`; keep the lookups in `TenantAuthenticationService` under ADR-004 until they move to OpenRegister) and Q3 (the active tenant is OpenRegister's active organisation, read through `OrganisationService::getActiveOrganisation()`). Tenancy decision 3 (the session leads).
- Dependencies: `dossiq/tenant-isolation-names-the-control-that-runs` (https://github.com/ConductionNL/dossiq/issues/3465), which rewrites `TenantContextMiddleware` and the hardening checklist first. Chained after `dossiq/tenancy-onto-openregister-organisation` (https://github.com/ConductionNL/dossiq/issues/3466) but does not wait for its held tasks.
- Build rules: openspec/woo-build-rules.md

## Why

Read on `development` at 0f95836e9, and on `ConductionNL/openregister` branch `development`.

- **The token path binds nothing.** Since step 3 the session decides the tenant, and the
  `tenant_id` claim is not trusted. `TenantJwtService` only validates tokens minted elsewhere,
  `TenantClaimValidationMiddleware` returns early without a Bearer token, and
  `TenantClaimMismatchException` is its error. `SaasServiceRegistrar` builds `TenantJwtService` from
  the app config key `jwt_signing_secret`. `PortalAssertionVerifier` reads the same key, so the key
  stays.
- **OpenRegister already keeps the active organisation.** `OrganisationService::getActiveOrganisation(?array $preloadedOrgs = null): ?Organisation`
  reads it per user, `setActiveOrganisation(string $organisationUuid): bool` checks access before it
  writes, and OpenRegister routes both as `GET /api/organisations/active` and
  `POST /api/organisations/{uuid}/set-active`. dossiq keeps a second choice:
  `TenantSessionService` stores its own session key and has its own `switchTo()` and `clear()`,
  `TenantController::switchTenant()` routes it, and `TenantContextMiddleware` binds it.
- **`TenantMiddleware` makes one check worth keeping.** For a signed-in user who is not a platform
  admin, on a controller that is not exempt, it refuses with 403 when the tenant's status is not
  `active`. OpenRegister's `TenantQuotaMiddleware` makes that check on OpenRegister's routes only.
  Deleting `TenantMiddleware` without moving the check would let a suspended organisation's members
  keep working on dossiq's routes. A user with no tenant is let through today (single-tenant
  installs), and stays let through.
- `TenantAuthenticationService` holds `listTenantsForUser()`, `resolveUserRole()`,
  `loadActiveMatrix()` and `validateMandateMatrix()` over the `tenantUser` and `tenantMandate`
  satellites that decision 2b keeps in dossiq. OpenRegister has no per-membership role or mandate
  matrix, so these stay until it does.

## What changes

- Delete `TenantJwtService`, `TenantClaimValidationMiddleware`, `TenantClaimMismatchException`,
  their registrations and their tests (Q2).
- `TenantContext` reads OpenRegister's active organisation itself; no middleware binds it (Q3).
- `TenantSessionService::activeTenantId()` answers OpenRegister's active organisation, when the
  user's `tenantUser` memberships also list it. Its session key, `switchTo()` and `clear()` go (Q3).
- Delete `TenantMiddleware` and `TenantContextMiddleware`, and the `tenant#switchTenant` route and
  method (Q3).
- `MandateValidationMiddleware` refuses a non-`active` organisation with 403, with the platform admin
  and exempt-controller rules `TenantMiddleware` used.
- The hardening checklist stops citing the deleted classes.
- ADR-004 covers `TenantAuthenticationService` for rule 4 and records Q2 and Q3.

## What does not change

- `TenantController`'s `current`, `memberships`, `provision` and `usage` endpoints. Their end state
  is not decided, so `TenantController.php` stays counted under rule 4.
- The membership, role and mandate lookups.
- OpenRegister.

## Gate 23 effect, with the other two changes built

| Finding | After `tenant-isolation-names-the-control-that-runs` | After this change too |
| --- | --- | --- |
| rule 7 tenant-boundary, by name | 3 files | 0 |
| rule 4 `Tenant*.php` | 13 files | 8, of which `TenantAuthenticationService` is covered by ADR-004 |
