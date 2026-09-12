# first-time-setup Specification

## Purpose
Give dossiq a first-time setup flow that (a) is rendered by the abstract `CnSetupWizard`, (b) gates the app until the OpenRegister register/schemas are initialised, and (c) lets an admin seed bezwaar/beroep (and the other repair seeds) from the UI — which is impossible today because OpenRegister enforces RBAC on `saveObject` and the browser request runs without create rights. Seeding therefore MUST run server-side with system privileges.

## Requirements

### Requirement: REQ-SETUP-PRO-001 — dossiq Declares Its Setup Steps In The Manifest

dossiq SHALL declare a `setup` block in `src/manifest.json` with steps `welcome` (`info`, optional), `demo-data` (`choice`, optional), `load-demo-data` (`run-action`, optional), `register-check` (`run-action`, **required**), `dwangsom-secret` (`config-fields`, optional) and `done` (`summary`, optional), and SHALL set `completionConfigKey` to `setup_completed_version`.

dossiq SHALL NOT declare a `seed` step. The requirement used to mandate one, and the step was retired: the bezwaar/beroep case types it seeded live under `_caseTypes_disabled` in `bezwaar_seed_data.json`, so the seeder returned success with every counter at zero. Reporting that as done made the affordance one-shot and silently useless, and reporting it honestly left a step whose every click was a 422. The `seed` ACTION stays, for an operator who un-parks the data.

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

- **GIVEN** the bezwaar/beroep seed payload is parked under `_caseTypes_disabled`
- **WHEN** the wizard reads `GET /apps/dossiq/api/setup/status`
- **THEN** the payload SHALL NOT report a `seed` step, so the wizard cannot prompt for one
- **AND** it SHALL still report the steps the manifest does declare, `register-check` among them
- **AND** `POST /apps/dossiq/api/setup/action/seed` SHALL refuse with 422 and `success: false` rather than report a success-shaped zero
- **AND** the refusal SHALL NOT add the step back to the payload

### Requirement: REQ-SETUP-PRO-002 — Seeding Runs Server-Side With System Privileges

dossiq SHALL expose `POST /apps/dossiq/api/setup/action/{actionId}` (admin-only, CSRF-protected) whose `seed` action runs `SeedDataService::seedBezwaarBeroepData()` and the other `Seed*` repair steps **server-side with system privileges** (admin-context or `_rbac:false`), so OpenRegister `saveObject` succeeds regardless of the requesting user's object-create rights. The wizard SHALL NOT write OpenRegister objects directly from the browser.

#### Scenario: Wizard seed action succeeds where a browser write would be denied

@e2e exclude There is no `seed` step to be on, and the action cannot create the case types this scenario names: they are parked under `_caseTypes_disabled` in `lib/Settings/bezwaar_seed_data.json`, so the call answers 422 by design rather than creating anything. See the exclusion on "Optional seed does not gate" above. The privilege claim this requirement is really about, that seeding runs server-side rather than as a browser write, survives the retirement and needs a scenario that does not depend on the parked payload.

- **GIVEN** an admin on the `seed` step and bezwaar/beroep not yet seeded
- **WHEN** the wizard POSTs `setup/action/seed`
- **THEN** the server SHALL create the Bezwaar + Beroep caseTypes, their status types and role types
- **AND** the call SHALL NOT fail with *"User 'Anonymous' does not have permission to 'create'"*
- **AND** the action SHALL be idempotent (re-running reports the existing objects as skipped)

#### Scenario: occ command remains a CLI fallback

- **GIVEN** the same `SeedDataService` wiring
- **WHEN** an operator runs `occ dossiq:bezwaar:seed`
- **THEN** the seed SHALL produce the identical result as the wizard `seed` action

### Requirement: REQ-SETUP-PRO-003 — Setup Status Is Reported For The Wizard

dossiq SHALL expose `GET /apps/dossiq/api/setup/status` returning `{ version, completed, datasets, steps: { <id>: { done } } }`, where `register-check.done` reflects OpenRegister enabled AND `register` + `case_type_schema` configured. There SHALL be no `seed` key: the wizard no longer declares that step, and the payload is its step contract.

#### Scenario: Status drives gating and completion

- **GIVEN** the wizard queries setup status
- **WHEN** `register-check.done` is false
- **THEN** `completed` SHALL be false and the wizard SHALL gate on `register-check`
- **AND** once all required steps report done, dossiq SHALL write `setup_completed_version` to app config and the wizard SHALL stop gating
