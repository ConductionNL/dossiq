# first-time-setup delta: bezwaar-beroep-package

## MODIFIED Requirements

### Requirement: REQ-SETUP-PRO-001 — dossiq Declares Its Setup Steps In The Manifest

dossiq SHALL declare a `setup` block in `src/manifest.json` with steps `welcome` (`info`, optional), `demo-data` (`choice`, optional), `load-demo-data` (`run-action`, optional), `register-check` (`run-action`, **required**), `dwangsom-secret` (`config-fields`, optional) and `done` (`summary`, optional), and SHALL set `completionConfigKey` to `setup_completed_version`.

dossiq SHALL NOT declare a `seed` step. The requirement used to mandate one, and the step was retired while the bezwaar/beroep seed data was parked and every click answered 422. The `seed` ACTION stays and installs the on-request `bezwaar-beroep` package (case-types REQ-CT-50); offering it in the wizard again is a product choice that has not been made.

The declared step list and the reported step list SHALL agree in both directions. A step the manifest renders but the status never reports is a step the wizard cannot gate on; a step the status reports but no manifest renders is one the wizard can never prompt for.

#### Scenario: Required register-check gates the app

- **GIVEN** dossiq is enabled but its OpenRegister `register` / `case_type_schema` are not configured
- **WHEN** an admin opens the app
- **THEN** the abstract `CnSetupWizard` SHALL gate the shell on the `register-check` step
- **AND** the app's normal navigation SHALL NOT be reachable until `register-check` reports done

#### Scenario: Optional seed does not gate

@e2e exclude The `seed` step was RETIRED, so this scenario's second clause is false against the product and no test can make it true. Its payload is parked: the bezwaar/beroep case types live under `_caseTypes_disabled` in `lib/Settings/bezwaar_seed_data.json`, so the seeder returned success with every counter at zero, which made the affordance one-shot and silently useless. Reporting that honestly instead left a step whose every click was a 422, so the step went and the action stayed for an operator who un-parks the data. The rewrite this exclusion was waiting on has now landed: REQ-SETUP-PRO-001 above states the retirement, and "No step is offered that the wizard cannot fulfil" below describes the wizard that ships, which is where `tests/e2e/case-type-edit-and-setup.spec.ts` "the wizard offers no step the seed action cannot fulfil" is now cited. THIS scenario stays excluded, because its own second clause is still about an offered `seed` step and rewording it to mean some other optional step would quietly change what it requires.

- **GIVEN** the register is initialised but no bezwaar/beroep data is seeded
- **WHEN** an admin opens the app
- **THEN** the app SHALL be usable, its shell reachable behind the wizard
- **AND** the outstanding optional step SHALL be offered (auto-opened once, dismissible) and re-runnable from the admin page

#### Scenario: No step is offered that the wizard cannot fulfil

- **GIVEN** the manifest declares no `seed` step
- **WHEN** the wizard reads `GET /apps/dossiq/api/setup/status`
- **THEN** the payload SHALL NOT report a `seed` step, so the wizard cannot prompt for one
- **AND** it SHALL still report the steps the manifest does declare, `register-check` among them
- **AND** `POST /apps/dossiq/api/setup/action/seed` SHALL answer success when the package installed or was already current, and 422 with `success: false` when the installer refused it, never a success-shaped zero
- **AND** neither answer SHALL add the step back to the payload

### Requirement: REQ-SETUP-PRO-002 — Seeding Runs Server-Side With System Privileges

dossiq SHALL expose `POST /apps/dossiq/api/setup/action/{actionId}` (admin-only, CSRF-protected) whose `seed` action installs the on-request case definition package `bezwaar-beroep` through the package installer **server-side**, in the admin's request, so OpenRegister `saveObject` succeeds regardless of what the browser could write. The wizard SHALL NOT write OpenRegister objects directly from the browser.

#### Scenario: Wizard seed action succeeds where a browser write would be denied

@e2e exclude covered by the package journey tests/e2e/packages/bezwaar-beroep.spec.ts, which cites case-types REQ-CT-50 (bezwaar-beroep-package); this scenario names the same call

- **GIVEN** an admin and bezwaar/beroep not yet installed
- **WHEN** the client POSTs `setup/action/seed`
- **THEN** the server SHALL create the Bezwaar and Beroep case types, their status types, role types and flows
- **AND** the call SHALL NOT fail with *"User 'Anonymous' does not have permission to 'create'"*
- **AND** the action SHALL be idempotent (re-running reports the package as current and writes nothing)

#### Scenario: occ command remains a CLI fallback

@e2e exclude a command line, not a screen; the packages workflow runs `occ dossiq:package:install --all` on a clean instance and reads the rows back with Newman

- **GIVEN** the same package installer
- **WHEN** an operator runs `occ dossiq:package:install bezwaar-beroep`
- **THEN** the install SHALL produce the identical result as the `seed` action
