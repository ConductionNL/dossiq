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

### Requirement: The first run names the minimum an instance needs (REQ-SETUP-010)

dossiq SHALL declare the minimum configuration an instance needs before it
can take a case: an organisation, a selected mail account, at least one
published case type, at least one role with a holder, and a working
calendar. Each item SHALL be read live at request time and SHALL NOT be a
stored completion flag. An item whose read fails SHALL report not done and
SHALL name the failure. Only `register-check` SHALL gate the app; no
readiness item SHALL block it.

#### Scenario: an administrator sees what is still missing
@e2e tests/e2e/first-run-and-the-tour.spec.ts

- **GIVEN** an instance with a register but no published case type
- **WHEN** an administrator opens the first run screen
- **THEN** the case type item SHALL read not done
- **AND** the organisation item SHALL read done

#### Scenario: a readiness item re-reads itself
@e2e tests/e2e/first-run-and-the-tour.spec.ts

- **GIVEN** a readiness item reading not done
- **WHEN** the administrator satisfies it and returns
- **THEN** it SHALL read done without the page being reinstalled

#### Scenario: an unconfigured instance is still usable

- **GIVEN** an instance where four of the five items read not done
- **WHEN** an administrator opens the app
- **THEN** the navigation SHALL be reachable
- **AND** only `register-check` SHALL gate it

#### Scenario: an item whose read throws is not done

- **GIVEN** a readiness item whose read raises
- **WHEN** the status is read
- **THEN** the item SHALL report not done
- **AND** it SHALL name the failure

### Requirement: Every readiness item names the screen that satisfies it (REQ-SETUP-011)

Each readiness item SHALL name the screen an administrator goes to in order
to satisfy it. The declared item list and the reported item list SHALL
agree in both directions: no item SHALL be rendered that the status does
not report, and no item SHALL be reported that no screen can satisfy.

#### Scenario: an item leads somewhere
@e2e tests/e2e/first-run-and-the-tour.spec.ts

- **GIVEN** the mail account item reading not done
- **WHEN** an administrator follows it
- **THEN** they SHALL land on the mail settings screen

#### Scenario: the two lists agree

- **GIVEN** the declared readiness items and the reported ones
- **WHEN** they are compared
- **THEN** every declared item SHALL be reported
- **AND** every reported item SHALL be declared

### Requirement: The tour is per surface and per person (REQ-SETUP-012)

Tour completion SHALL be recorded per person and per surface, not once for
the whole app. A person who has finished every existing surface SHALL be
offered the step for a surface added later. A tour step naming a surface
that no longer exists SHALL be reported as broken beside the readiness
items and SHALL NOT be silently skipped.

#### Scenario: a handler who joined later is still taught
@e2e tests/e2e/first-run-and-the-tour.spec.ts

- **GIVEN** an instance whose administrator finished the tour
- **WHEN** a new handler opens the app for the first time
- **THEN** they SHALL be offered the tour

#### Scenario: a new surface is offered to someone who finished the rest
@e2e tests/e2e/first-run-and-the-tour.spec.ts

- **GIVEN** a person who completed every tour step
- **WHEN** a surface with its own step is added
- **THEN** that step SHALL be offered to them
- **AND** the finished steps SHALL NOT be offered again

#### Scenario: a step that lost its surface is reported

- **GIVEN** a tour step naming a page that no longer exists
- **WHEN** the first run status is read
- **THEN** the step SHALL be reported as broken
- **AND** it SHALL name the missing surface

### Requirement: Each example data card loads itself

The `demo-data` setup step MUST be a cards choice step with `loadAction: load-demo-data`. The setup wizard MUST NOT carry a separate run-action step that loads the picked dataset.

#### Scenario: The operator loads a dataset from its card

- GIVEN the setup wizard shows the example data cards
- WHEN the operator presses Load on a card
- THEN the wizard posts `{ "dataset": <card value> }` to `/api/setup/action/load-demo-data`
- AND the server loads that dataset
- AND the server records the dataset as the pick only after the load succeeds
- @e2e exclude the card and its spinner are CnSetupWizard UI, tested in nextcloud-vue; the posted body is covered by tests/Unit/Controller/SetupControllerStatusTest.php

#### Scenario: An unknown dataset is refused

- GIVEN a dataset id that no card offers
- WHEN it is posted to `/api/setup/action/load-demo-data`
- THEN the server answers 400 with `success: false`
- AND nothing is loaded or stored
- @e2e tests/e2e/spec-coverage/demo-data-setup-step.spec.ts

#### Scenario: A call without a body keeps working

- GIVEN a dataset was stored through `/api/setup/config`
- WHEN `/api/setup/action/load-demo-data` is called without a body
- THEN the stored dataset is loaded
- @e2e tests/e2e/spec-coverage/demo-data-setup-step.spec.ts

#### Scenario: A failed load leaves the step open

- GIVEN the load of the posted dataset fails
- WHEN the server answers
- THEN the answer carries `success: false`
- AND no pick or decision is stored
- @e2e exclude needs a load that fails on a live instance; covered by tests/Unit/Controller/SetupControllerStatusTest.php

### Requirement: Setup status reports every manifest step

`GET /api/setup/status` MUST report a `done` state for every step id in `manifest.setup.steps`.

#### Scenario: The status ids match the manifest

- GIVEN the Dossiq manifest
- WHEN an administrator reads `/api/setup/status`
- THEN `steps` holds an entry for every manifest step id
- AND the retired load step is not reported
- @e2e tests/e2e/spec-coverage/demo-data-setup-step.spec.ts

### Requirement: Provisioning runs from the admin settings page

Provisioning, repair and register-import actions MUST NOT be wizard steps. The admin settings page MUST offer the register import. In Dossiq that is the existing Re-import configuration button, which runs the same forced import as `init-register`. The `init-register` setup action MUST keep working for runbooks and scripts.

#### Scenario: The administrator repairs the register from the admin page

- GIVEN an administrator on the Dossiq admin settings page
- WHEN they press Re-import configuration
- THEN the page posts to `/api/settings/load`
- AND the register and schemas are imported with `force: true`
- AND the result message is shown on the page
- @e2e exclude admin settings button calling an existing setup action; the action is covered by tests/Unit/Controller/SetupControllerStatusTest.php

#### Scenario: The wizard no longer asks

- GIVEN the Dossiq manifest
- WHEN the setup steps are read
- THEN none of `register-check` is a step
- @e2e exclude a manifest property; asserted by tests/Unit/Controller/SetupControllerStatusTest.php
