# Tasks: setup-wizard-stays-closed-on-server

## 1. Library

- [x] 1.1 Bump `@conduction/nextcloud-vue` to `^2.71.0` in `package.json` and `package-lock.json`.
- [x] 1.2 Copy `app-manifest-v2.schema.json` (2.53.0) from the package into `tests/schemas/`.

## 2. Server-side close

- [x] 2.1 `src/manifest.json`: `setup.dismissAction: "dismiss-setup"`.
- [x] 2.2 `SetupController::runAction('dismiss-setup')` stores `setup_dismissed_version` and nothing else. Admin-only, like every setup action.
- [x] 2.3 `SetupController::status()` reports `dismissed` as the stored version (int) once it is set.
- [x] 2.4 PHPUnit: the action stores only the version, a real demo choice survives, status reports `dismissed`, and status without a close carries no `dismissed`.

## 3. Config-fields intro

- [x] 3.1 `dwangsom-secret` step gets a `body`, with en and nl entries in `l10n/`.

## 4. Verification

- [x] 4.1 composer check:strict, vitest, lint, format, test:l10n, manifest check, build.
- [ ] 4.2 Live on :8099: close the wizard in one browser context, open dossiq in a fresh context, the wizard stays closed. The intro shows on the dwangsom step.
