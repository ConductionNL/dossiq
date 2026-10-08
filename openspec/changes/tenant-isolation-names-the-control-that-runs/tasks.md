# Tasks: tenant-isolation-names-the-control-that-runs

Resolves dossiq#2470. Build rules: `openspec/woo-build-rules.md`.

Every task names the requirement it meets and the test that proves it. A test marked
**fails today** must be run on `origin/development` before the change and seen red; note the
failure line in the PR body.

## 1. Delete the inert pipeline

- [x] 1.1 Delete `lib/Middleware/TenantIsolationMiddleware.php` and its registration in
  `lib/AppInfo/Registrar/MiddlewareRegistrar.php`, with the comments there that describe a
  search_path (REQ-TIS-001).
  - **fails today**: `tests/Unit/Architecture/NoSearchPathTenancyTest.php`
    `testNoCodeLineUnderLibSetsASearchPath`. Strip whole-line comments the way gate 23's
    `_code_lines` does, so the test and the gate agree.
  - **fails today**: a registrar test, `tests/Unit/AppInfo/MiddlewareRegistrarTest.php`
    `testTheIsolationMiddlewareIsNotRegistered`, built on a real `IRegistrationContext` double
    that records every `registerMiddleware()` call.
- [x] 1.2 Delete `TenantSchemaProvisioner`, `TenantProvisioningService`, `TenantSeedService` and
  `TenantWelcomeMailer`, and `TenantIsolationMiddlewareTest`, `TenantSchemaProvisionerTest` and
  `TenantProvisioningServiceTest`. Remove their entries from
  `tests/Unit/Architecture/catch-return-null.allowlist.json` (REQ-TIS-001).
  - **fails today**: `NoSearchPathTenancyTest` `testNoneOfTheFiveDeletedClassesIsNamedUnderLib`.
- [x] 1.3 `TenantContext::bind()` takes the tenant only; `getSchemaName()` and the stored schema
  name go. `TenantContextMiddleware` no longer injects `TenantProvisioningService` (REQ-TIS-001, REQ-TIS-004).
  - **fails today**: `tests/Unit/Middleware/TenantContextMiddlewareTest.php`
    `testBindingASessionTenantNeedsNoSchemaName`, and `tests/Unit/Service/TenantContextTest.php`
    `testBindTakesTheTenantOnly`.
- [x] 1.4 Rewrite the `tenantUser.role` description in both register descriptors so it names no
  deleted class, and bump the register `info.version` (REQ-TIS-001).
  - **fails today**: `NoSearchPathTenancyTest`
    `testNeitherDescriptorNamesADeletedPipelineClass`.

## 2. The checklist names the control that runs

- [x] 2.1 In `TenantAuditTrailService::hardeningChecklist()`, `tenant_scoped_queries` and
  `no_tenant_info_leak` cite OpenRegister's organisation row filter (`MagicSearchHandler`,
  multitenancy). Their status is `pass` only when a live call to OpenRegister's
  `SettingsService::isMultiTenancyEnabled()` answers true, and `unverified` when it answers false,
  throws, or OpenRegister is absent. Resolve the service the way `getAuditTrailMapper()` already
  does. Read the real method on `ConductionNL/openregister` branch `development`
  (`lib/Service/SettingsService.php`) before doubling it (REQ-TIS-002).
  - **fails today**: `tests/Unit/Service/TenantAuditTrailServiceTest.php`
    `testTheIsolationItemsCiteTheOpenRegisterRowFilter`,
    `testTheIsolationItemsAreUnverifiedWhenMultitenancyIsOff` and
    `testTheIsolationItemsAreUnverifiedWhenOpenRegisterIsAbsent`.
- [x] 2.2 `isolation_pen_test` describes row isolation, not schema isolation, and stays
  `unverified` (REQ-TIS-002).
  - **fails today**: `TenantAuditTrailServiceTest`
    `testNoChecklistItemMentionsASchemaOrASearchPath`.

## 3. Ending a tenancy deletes nothing

- [x] 3.1 Remove the `tenantSaas#destroy` route, `TenantSaasController::destroy()` and
  `TenantSaasService::delete()`, and the `DELETE` operation in `docs/openapi/tenant-saas.yaml`
  (REQ-TIS-003).
  - **fails today**: `tests/Unit/AppInfo/CanonicalRouteMethodContractTest.php` (or a new
    `TenantRoutesTest`) `testNoRouteDeletesATenant`, read from `appinfo/routes.php`.
  - The hydra route-reachability gate must show no dangling route.
- [x] 3.2 Replace the `TENANT_SCHEMA_DELETED` paragraph in
  `docs/leverancier-zaakportaal/deployment.md` with what an operator does see when a tenancy ends:
  the status change and its audit entry (REQ-TIS-003).
  - Check: `git grep TENANT_SCHEMA_DELETED` and `git grep archiveAndDelete -- docs` return
    nothing; paste both in the PR body.

## 4. Verify and deliver

- [x] 4.1 `TMPDIR` set to a sibling directory beside the clone, never inside it. While building, run
  only the unit tests of touched classes with
  `./vendor/bin/phpunit -c phpunit-unit.xml --no-coverage --filter '<Class>'` and judge by the
  `Tests:` line, because a green suite exits 1 without a coverage driver.
- [x] 4.2 Before push, once: `COMPOSER_PROCESS_TIMEOUT=0 composer check:strict`, then
  `npm run lint` and any other leg `code-quality.yml` requires. Then
  `scripts/run-hydra-gates.sh --base origin/development`, count the gates that ran, and paste gate
  23's output: no `search_path` finding, and none of the five deleted paths.
- [ ] 4.3 One PR, `--base development`, that closes dossiq#2470. Merge development in, never
  rebase. No `Co-Authored-By` on any commit. Done means merged on `development` with CI green.
