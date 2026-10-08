# Tasks: tenancy-onto-openregister-organisation-active-organisation

Part 2 of the `tenancy-onto-openregister-organisation` chain. Decisions Q2 and Q3, Ruben
2026-10-08. Build rules: `openspec/woo-build-rules.md`. Build after
`tenant-isolation-names-the-control-that-runs` is merged.

Every task names the requirement it meets and the test that proves it. A test marked
**fails today** must be run on `origin/development` before the change and seen red; note the
failure line in the PR body.

Read `OrganisationService::getActiveOrganisation()` and `Organisation::getStatus()` on
`ConductionNL/openregister` branch `development` (or in `vendor/`) before doubling them. Entity
getters are magic; the service returns `null` for a user with no active organisation.

## 1. The token path goes (Q2)

- [ ] 1.1 Delete `lib/Service/TenantJwtService.php`, `lib/Middleware/TenantClaimValidationMiddleware.php`,
  `lib/Middleware/TenantClaimMismatchException.php`, the `TenantJwtService` factory in
  `lib/AppInfo/Registrar/SaasServiceRegistrar.php`, the registration in `MiddlewareRegistrar`, and
  `TenantJwtServiceTest` and `TenantClaimValidationMiddlewareTest`. Keep the `jwt_signing_secret`
  config key (REQ-TAO-001).
  - **fails today**: `tests/Unit/Architecture/NoTenantTokenPathTest.php`
    `testNoneOfTheThreeTokenClassesIsNamedUnderLib`.
  - **fails today**: `tests/Unit/AppInfo/MiddlewareRegistrarTest.php`
    `testNoClaimMiddlewareIsRegistered`.
  - Stays green: `PortalAssertionVerifier`'s tests, which read the same secret.
- [ ] 1.2 In `TenantAuditTrailService::hardeningChecklist()`, drop `claim_validation` and make
  `no_hardcoded_secrets` cite only classes that exist (REQ-TAO-001).
  - **fails today**: `tests/Unit/Service/TenantAuditTrailServiceTest.php`
    `testNoChecklistItemCitesADeletedTenantClass`.

## 2. The active tenant is OpenRegister's (Q3)

- [ ] 2.1 `TenantContext` resolves the tenant on first read from
  `OrganisationService::getActiveOrganisation()`, projected the way `TenantOrganisationResolver`
  projects an Organisation (`uuid`, `id`, `slug`, `status`). It stays unbound when the service
  answers `null`, throws, or OpenRegister is absent. `bind()` goes (REQ-TAO-002).
  - **fails today**, through the caller: `tests/Unit/Middleware/MandateValidationMiddlewareTest.php`
    `testTheMandateCheckRunsForOpenRegistersActiveOrganisation` and
    `testNoActiveOrganisationLeavesTheRequestUnchecked`, built on the real `TenantContext`.
- [ ] 2.2 `TenantSessionService::activeTenantId()` answers the active organisation's uuid only when
  the user's `tenantUser` memberships list it, and `null` otherwise. Its session key, `switchTo()`
  and `clear()` go (REQ-TAO-002).
  - **fails today**: `tests/Unit/Service/TenantSessionServiceTest.php`
    `testTheActiveTenantIsOpenRegistersActiveOrganisation`,
    `testAnActiveOrganisationTheUserHasNoMembershipOfIsNotTheTenant` and
    `testTheServiceKeepsNoSessionKeyOfItsOwn`.
- [ ] 2.3 `MandateValidationMiddleware` refuses with 403 when the bound organisation's status is not
  `active`, before the action check, for a signed-in user who is not a platform admin, on a
  controller outside the exempt list `TenantMiddleware` had (`SettingsController`,
  `DashboardController`, `TenantController`, OpenRegister's `GenericHealthController` and
  `GenericMetricsController`). The answer shape matches what `TenantMiddleware::afterException()`
  gave (REQ-TAO-003).
  - **fails today**: `MandateValidationMiddlewareTest`
    `testASuspendedOrganisationIsRefusedOnADossiqRoute`,
    `testARetainedOrganisationIsRefusedOnADossiqRoute`,
    `testAPlatformAdminIsNotRefused` and `testAUserWithNoOrganisationIsLetThrough`.
- [ ] 2.4 Delete `TenantMiddleware`, `TenantContextMiddleware`, their registrations and tests, and
  the `tenant#switchTenant` route and `TenantController::switchTenant()`. Switching is
  OpenRegister's `POST /api/organisations/{uuid}/set-active`. Check `src/` for a caller of
  `/api/tenants/switch` first and re-point it (REQ-TAO-002, REQ-TAO-003).
  - **fails today**: `MiddlewareRegistrarTest` `testNoTenantMiddlewareIsRegistered`, and
    `tests/Unit/Controller/TenantControllerContractTest.php` `testThereIsNoSwitchTenantRoute`.
  - The hydra route-reachability gate must show no dangling route.

## 3. `TenantController` moves to OpenRegister (Q5)

- [ ] 3.1 Remove the `tenant#current`, `tenant#memberships`, `tenant#provision` and `tenant#usage`
  routes and their methods; their OpenRegister equivalents are `organisation#getActive`,
  `organisation#index`, `organisation#activate`, and `organisation#usage` with `organisation#show`
  (see the proposal's table). Re-point any caller in `src/` first; on 2026-10-08 there was none
  (REQ-TAO-005).
  - **fails today**: `tests/Unit/Controller/TenantControllerContractTest.php` replaced by
    `tests/Unit/AppInfo/TenantRoutesAreGoneTest.php` `testNoRouteNamesTheTenantController`, read
    from `appinfo/routes.php`.
  - Guard: `tests/Unit/Architecture/NoDossiqTenantApiCallerTest.php`
    `testNoFileUnderSrcCallsTheDossiqTenantApi`, which fails if a `src/` file names
    `/apps/dossiq/api/tenants`.
  - The hydra route-reachability gate must show no dangling route.
- [ ] 3.2 Delete `lib/Controller/TenantController.php` and its tests, and the `TenantService`
  methods left with no caller: `provisionTenant()`, `getResourceUsage()`, `getTenantForUser()` and
  `getTenantStatus()`. Keep `isPlatformAdmin()` if task 2.3 calls it (REQ-TAO-005).
  - **fails today**: `NoTenantTokenPathTest` `testTenantControllerIsNotUnderLib`, and the hydra
    stub-scan and orphan gates show no caller-less method left on `TenantService`.

## 4. ADR-004 and close out

- [ ] 4.1 Update `openspec/architecture/adr-004-tenant-cluster-adr-022-exception.md`: add
  `lib/Service/TenantAuthenticationService.php` with `(gate 23 rules: 4)` under a section that says
  its membership, role and mandate lookups stay until they move to OpenRegister (Q2); record Q3; drop
  every path this change deleted, `TenantController.php` included; and update "Status of the work" (REQ-TAO-004).
  - Evidence: `run-hydra-gates.sh --base origin/development` output for gate 23, pasted in the PR
    body: no `or-capability:tenant-boundary` finding, and `TenantAuthenticationService.php` printed
    as suppressed for rule 4 only.
- [ ] 4.2 `tests/e2e/tenant-follows-the-active-organisation.spec.ts`: a user who belongs to two
  organisations sets one active through OpenRegister, and a dossiq write is checked against that
  organisation's mandate matrix; suspending it refuses the next dossiq write; an admin reads its
  usage from `GET /apps/openregister/api/organisations/{uuid}/usage`. Cite REQ-TAO-002, REQ-TAO-003
  and REQ-TAO-005.
- [ ] 4.3 `TMPDIR` set to a sibling directory beside the clone, never inside it. While building, run
  only the unit tests of touched classes with
  `./vendor/bin/phpunit -c phpunit-unit.xml --no-coverage --filter '<Class>'` and judge by the
  `Tests:` line, because a green suite exits 1 without a coverage driver.
- [ ] 4.4 Before push, once: `COMPOSER_PROCESS_TIMEOUT=0 composer check:strict`, then `npm run lint`
  and any other leg `code-quality.yml` requires. Then
  `scripts/run-hydra-gates.sh --base origin/development` and count the gates that ran. The coverage
  guard needs tests for every added statement.
- [ ] 4.5 One PR, `--base development`. Merge development in, never rebase. No `Co-Authored-By` on
  any commit. Done means merged on `development` with CI green.
