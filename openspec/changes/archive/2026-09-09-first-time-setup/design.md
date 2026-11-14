# Design — dossiq first-time setup

## Steps (manifest `setup.steps[]`)

| id | type | required | what it does |
|----|------|----------|--------------|
| `welcome` | `info` | no | one-paragraph intro |
| `register-check` | `config-fields` | **yes** | shows OR availability + register/schema config; offers a `init-register` run-action if not initialised |
| `seed` | `run-action` (`action: seed`) | no | server-side seed of bezwaar/beroep + other repair seeds |
| `done` | `summary` | no | health recap + links to Cases / Bezwaar & Beroep |

`register-check` is the only REQUIRED step (the app is unusable without an initialised register), so it gates the shell via the CnAppRoot `setup` phase. `seed` is optional (dismissible / re-runnable from the admin page).

## Server-side contract (the privilege fix)

- `GET /apps/dossiq/api/setup/status` → `{ version, completed, steps: { <id>: { done, detail } } }`. `register-check.done` = OR enabled AND `register` + `case_type_schema` configured. `seed.done` = bezwaar/beroep caseTypes exist.
- `POST /apps/dossiq/api/setup/action/{actionId}` (admin-only, CSRF) → runs the named action server-side and returns a summary. Actions: `init-register` (ConfigurationService import), `seed` (`SeedDataService::seedBezwaarBeroepData()` + the other `Seed*` repair steps).

Both endpoints run with system privileges so OR `saveObject` succeeds (RBAC). This replaces the `occ dossiq:bezwaar:seed`-only path — the command stays as a CLI fallback; the controller shares the same `SeedDataService` + admin-context wiring.

## Reuse / not rebuild

- The wizard chrome, step rendering, gating phase and admin-page entry come from the central `CnSetupWizard` + CnAppRoot change — dossiq only declares `manifest.setup` and implements the two endpoints.
- `register-check` reuses the SAME schema-driven config fields the admin page renders (`fieldsFromSchema`), per the "reuse settings components in a different way" goal.
- `seed` action reuses `lib/Service/SeedDataService.php` and the existing `Seed*` repair steps (`appinfo/info.xml`).

## Requirements this surfaces for the central feature

- A `run-action` step type that POSTs to `/api/setup/action/{id}` and shows progress + result.
- A `config-fields` step type bound to a JSON Schema + the app settings POST.
- A `required` flag per step + a CnAppRoot phase that gates on required-unmet.
- A standard `GET /api/setup/status` shape.
