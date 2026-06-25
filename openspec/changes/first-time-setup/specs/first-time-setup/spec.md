# first-time-setup Specification

**Status:** proposed
**Scope:** procest
**Tier:** V1
**Depends on:** the abstract setup wizard (hydra ADR-04x, `@conduction/nextcloud-vue` `CnSetupWizard` + manifest `setup` block + CnAppRoot `setup` phase). This per-app spec is written FIRST and is a requirements source for that central change.

## Purpose

Give procest a first-time setup flow that (a) is rendered by the abstract `CnSetupWizard`, (b) gates the app until the OpenRegister register/schemas are initialised, and (c) lets an admin seed bezwaar/beroep (and the other repair seeds) from the UI — which is impossible today because OpenRegister enforces RBAC on `saveObject` and the browser request runs without create rights. Seeding therefore MUST run server-side with system privileges.

## ADDED Requirements

### Requirement: REQ-SETUP-PRO-001 — procest Declares Its Setup Steps In The Manifest

procest SHALL declare a `setup` block in `src/manifest.json` with steps `welcome` (`info`, optional), `register-check` (`config-fields`, **required**), `seed` (`run-action`, optional) and `done` (`summary`, optional), and SHALL set `completionConfigKey` to `setup_completed_version`.

#### Scenario: Required register-check gates the app

- **GIVEN** procest is enabled but its OpenRegister `register` / `case_type_schema` are not configured
- **WHEN** an admin opens the app
- **THEN** the abstract `CnSetupWizard` SHALL gate the shell on the `register-check` step
- **AND** the app's normal navigation SHALL NOT be reachable until `register-check` reports done

#### Scenario: Optional seed does not gate

- **GIVEN** the register is initialised but no bezwaar/beroep data is seeded
- **WHEN** an admin opens the app
- **THEN** the app SHALL be usable
- **AND** the `seed` step SHALL be offered (auto-opened once, dismissible) and re-runnable from the admin page

### Requirement: REQ-SETUP-PRO-002 — Seeding Runs Server-Side With System Privileges

procest SHALL expose `POST /apps/procest/api/setup/action/{actionId}` (admin-only, CSRF-protected) whose `seed` action runs `SeedDataService::seedBezwaarBeroepData()` and the other `Seed*` repair steps **server-side with system privileges** (admin-context or `_rbac:false`), so OpenRegister `saveObject` succeeds regardless of the requesting user's object-create rights. The wizard SHALL NOT write OpenRegister objects directly from the browser.

#### Scenario: Wizard seed action succeeds where a browser write would be denied

- **GIVEN** an admin on the `seed` step and bezwaar/beroep not yet seeded
- **WHEN** the wizard POSTs `setup/action/seed`
- **THEN** the server SHALL create the Bezwaar + Beroep caseTypes, their status types and role types
- **AND** the call SHALL NOT fail with *"User 'Anonymous' does not have permission to 'create'"*
- **AND** the action SHALL be idempotent (re-running reports the existing objects as skipped)

#### Scenario: occ command remains a CLI fallback

- **GIVEN** the same `SeedDataService` wiring
- **WHEN** an operator runs `occ procest:bezwaar:seed`
- **THEN** the seed SHALL produce the identical result as the wizard `seed` action

### Requirement: REQ-SETUP-PRO-003 — Setup Status Is Reported For The Wizard

procest SHALL expose `GET /apps/procest/api/setup/status` returning `{ version, completed, steps: { <id>: { done, detail } } }`, where `register-check.done` reflects OpenRegister enabled AND `register` + `case_type_schema` configured, and `seed.done` reflects the Bezwaar/Beroep caseTypes existing.

#### Scenario: Status drives gating and completion

- **GIVEN** the wizard queries setup status
- **WHEN** `register-check.done` is false
- **THEN** `completed` SHALL be false and the wizard SHALL gate on `register-check`
- **AND** once all required steps report done, procest SHALL write `setup_completed_version` to app config and the wizard SHALL stop gating
