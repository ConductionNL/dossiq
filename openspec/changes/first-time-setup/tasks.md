# Tasks — procest first-time setup

> Blocked on the central change landing (`nextcloud-vue` `cn-setup-wizard` + manifest `setup` schema). Phase A here is the SPEC only; implementation tasks below run in Phase D.

## Phase 1: Manifest

- [ ] Add a `setup` block to `src/manifest.json` with steps `welcome` / `register-check` (required) / `seed` / `done` and `completionConfigKey: setup_completed_version`.
- [ ] Reference the admin-config JSON Schema for `register-check` (`config-fields`).

## Phase 2: Server-side contract

- [ ] Add `lib/Controller/SetupController.php` with `status()` (GET) and `runAction()` (POST `/action/{actionId}`), admin-only (`#[AuthorizedAdminSetting]` or admin guard), CSRF on POST.
- [ ] `status()` reports `register-check` (OR enabled + `register`/`case_type_schema` configured) and `seed` (bezwaar/beroep caseTypes exist) done-state.
- [ ] `runAction('init-register')` → ConfigurationService import; `runAction('seed')` → `SeedDataService::seedBezwaarBeroepData()` + the other `Seed*` steps, running privileged (admin-context / `_rbac:false`) so OR `saveObject` succeeds.
- [ ] Register both routes in `appinfo/routes.php` with auth attributes (gate-5/route-reachability).
- [ ] Write the `setup_completed_version` app-config value when required steps pass.

## Phase 3: Verify

- [ ] Live: fresh enable with register uninitialised → CnAppRoot gates to the wizard; complete `register-check` → app usable; run `seed` from the wizard → bezwaar/beroep seeded with NO RBAC error; both modals open from the admin page.
- [ ] `occ procest:bezwaar:seed` still works as the CLI fallback (shares `SeedDataService`).
