# first-time-setup Specification

## Purpose
Give dossiq a first-time setup flow that (a) is rendered by the abstract `CnSetupWizard`, (b) gates the app until the OpenRegister register/schemas are initialised, and (c) lets an admin seed bezwaar/beroep (and the other repair seeds) from the UI — which is impossible today because OpenRegister enforces RBAC on `saveObject` and the browser request runs without create rights. Seeding therefore MUST run server-side with system privileges.

## Requirements

### Requirement: REQ-SETUP-PRO-001 — dossiq Declares Its Setup Steps In The Manifest

dossiq SHALL declare a `setup` block in `src/manifest.json` with steps `welcome` (`info`, optional), `demo-data` (`choice`, optional), `load-demo-data` (`run-action`, optional), `register-check` (`run-action`, **required**), `dwangsom-secret` (`config-fields`, optional) and `done` (`summary`, optional), and SHALL set `completionConfigKey` to `setup_completed_version`.

> **Corrected 2026-09-12.** This enumerated `welcome`, `register-check` as
> `config-fields`, `seed` and `done`, and the shipped wizard matched none of
> those three particulars: `register-check` is a `run-action`, the `seed` step
> was retired for the reason recorded on the scenario below, and `demo-data`,
> `load-demo-data` and `dwangsom-secret` were never listed. Measured against
> `src/manifest.json` rather than inferred.
>
> The `seed` ACTION is not retired and REQ-SETUP-PRO-002 still describes it
> accurately: `SetupController::runAction()` still answers `seed` and still
> calls `SeedDataService::seedBezwaarBeroepData()`. Only the wizard step went,
> so an operator who un-parks the data can still run it.
>
> No scenario is added here for the demo-data steps. Writing one would create a
> requirement nothing tests, which is the shape this spec has just been
> corrected out of.

#### Scenario: Required register-check gates the app

- **GIVEN** dossiq is enabled but its OpenRegister `register` / `case_type_schema` are not configured
- **WHEN** an admin opens the app
- **THEN** the abstract `CnSetupWizard` SHALL gate the shell on the `register-check` step
- **AND** the app's normal navigation SHALL NOT be reachable until `register-check` reports done

#### Scenario: Optional seed does not gate

@e2e exclude The `seed` step was RETIRED, so this scenario's second clause is false against the product and no test can make it true. Its payload is parked: the bezwaar/beroep case types live under `_caseTypes_disabled` in `lib/Settings/bezwaar_seed_data.json`, so the seeder returned success with every counter at zero, which made the affordance one-shot and silently useless. Reporting that honestly instead left a step whose every click was a 422, so the step went and the action stayed for an operator who un-parks the data. `tests/e2e/case-type-edit-and-setup.spec.ts` "the wizard offers no step the seed action cannot fulfil" asserts the retirement and the 422, deliberately uncited. The requirement above was corrected on 2026-09-12 to enumerate the steps that ship, and REQ-SETUP-PRO-002 was checked and needed no change: the `seed` action still exists and still calls `seedBezwaarBeroepData()`. This scenario stays excluded because its second clause names a step the product does not offer, and the honest close is to delete the scenario when the parked bezwaar data is either un-parked or dropped, rather than to write a demo-data scenario nothing tests.

- **GIVEN** the register is initialised but no bezwaar/beroep data is seeded
- **WHEN** an admin opens the app
- **THEN** the app SHALL be usable
- **AND** the `seed` step SHALL be offered (auto-opened once, dismissible) and re-runnable from the admin page

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

dossiq SHALL expose `GET /apps/dossiq/api/setup/status` returning `{ version, completed, steps: { <id>: { done, detail } } }`, where `register-check.done` reflects OpenRegister enabled AND `register` + `case_type_schema` configured, and `seed.done` reflects the Bezwaar/Beroep caseTypes existing.

#### Scenario: Status drives gating and completion

- **GIVEN** the wizard queries setup status
- **WHEN** `register-check.done` is false
- **THEN** `completed` SHALL be false and the wizard SHALL gate on `register-check`
- **AND** once all required steps report done, dossiq SHALL write `setup_completed_version` to app config and the wizard SHALL stop gating
