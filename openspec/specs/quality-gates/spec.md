---
status: done
status-note: Reverse-synced 2026-06-13 from an archived fully-implemented change; capability code confirmed present on development.
---
# quality-gates Specification

## Purpose
Defines the unified strict quality gate for dossiq: a single `composer check:strict` command that runs lint, PHPCS, PHPMD, Psalm, and PHPStan in sequence and fails on any violation, run on every pull request to `development`, `main`, or `beta`. It requires PHPMD to run with no baseline, keeps any PHPStan baseline minimal and documented, and pins the CI workflow to a served Codeberg runner so the gate is actually scheduled.

## Requirements

### Requirement: Unified strict quality gate

Dossiq SHALL expose a single unified quality gate, `composer check:strict`,
that runs lint, PHPCS, PHPMD, Psalm, and PHPStan in sequence and exits non-zero
if any tool reports a violation. This gate SHALL run on every pull request
targeting `development`, `main`, or `beta`.

#### Scenario: All tools pass on clean code

- **WHEN** `composer check:strict` runs against the current `lib/` tree
- **THEN** lint, PHPCS, PHPMD, Psalm, and PHPStan each exit zero
- **AND** the gate prints `ALL CHECKS PASSED` and exits zero

#### Scenario: A new violation fails the gate

- **WHEN** a change introduces a PHPCS, PHPMD, or PHPStan violation not covered
  by a documented ignore pattern or baseline entry
- **THEN** `composer check:strict` exits non-zero
- **AND** the `pre-merge-check-strict` CI workflow reports a failing status check

### Requirement: PHPMD runs with no baseline

The PHPMD gate SHALL run with no baseline file: every PHPMD violation in `lib/`
is fixed at source rather than suppressed. Intentional rule exceptions SHALL
use inline `@SuppressWarnings(PHPMD.<Rule>)` annotations with a written
justification, never a blanket rule removal or baseline.

#### Scenario: PHPMD passes without a baseline file

- **WHEN** `composer phpmd` runs
- **THEN** no `phpmd.baseline.xml` is referenced
- **AND** PHPMD reports zero violations

### Requirement: PHPStan runs with no baseline

The PHPStan gate SHALL run with no baseline file: every PHPStan error in `lib/`
is fixed at source rather than suppressed. Stub-precision false positives — the
only legitimate suppressions — SHALL be expressed as documented `ignoreErrors`
patterns in `phpstan.neon`, each carrying a written justification, never as
opaque baseline entries.

A baseline is prohibited because it decouples the gate's exit code from the
codebase's actual state: `composer check:strict` exits 0 while the suppressed
errors remain, and stale entries accumulate silently as the underlying code is
fixed. When this requirement was introduced, the 14-entry baseline was hiding
10 live errors and had already rotted 4 entries into no-ops.

#### Scenario: PHPStan passes without a baseline file

- **WHEN** `composer phpstan` runs
- **THEN** no `phpstan-baseline.neon` exists and `phpstan.neon` declares no
  `includes:` for one
- **AND** PHPStan analysis reports `[OK] No errors` with exit code 0

#### Scenario: A reintroduced baseline cannot silently hide errors

- **WHEN** a developer empties or deletes the suppression configuration
- **THEN** PHPStan's result SHALL be unchanged, because no error is being
  suppressed — the gate's green is bought entirely by the source

### Requirement: CI uses a served Codeberg runner

The `pre-merge-check-strict` workflow SHALL target a served Codeberg runner
label (`codeberg-small`) and SHALL NOT use the unserved `docker` label, so the
gate is actually scheduled and executed on pull requests.

#### Scenario: Workflow targets codeberg-small

- **WHEN** the `pre-merge-check-strict` workflow is triggered by a pull request
- **THEN** its job declares `runs-on: codeberg-small`
- **AND** the job is scheduled and runs `composer check:strict`

### Requirement: Every declared schema has a surface (REQ-QG-SHS-1)

A structural test SHALL read every schema declared in
`lib/Settings/dossiq_register.json` and `lib/Settings/register.d/*.json`
and SHALL fail for any slug that has no manifest page or widget, no
`deepLinks` entry, no reference under `lib/` outside `lib/Settings/`, no
parent with a surface, and no reason-bearing allowlist entry naming the
change that surfaces it or the app that reads it.

#### Scenario: A new schema without a surface fails
@e2e exclude structural; covered by SchemaHasSurfaceTest over a fixture register

- **GIVEN** a register fragment declaring schema `orphan`
- **WHEN** the test runs
- **THEN** it SHALL fail naming `orphan`

#### Scenario: A child of a shown parent passes
@e2e exclude structural; same test, one-hop branch

- **GIVEN** schema `case` on a page and schema `caseObject` referenced from `case`
- **WHEN** the test runs
- **THEN** `caseObject` SHALL pass

### Requirement: Schema-only registrations are retired or surfaced (REQ-QG-SHS-2)

The schemas the test names on `development` at the time of the change SHALL
each be retired with their seeds, surfaced by a named change, or
allowlisted with the app that reads them. The allowlist ceiling SHALL only
go down.

#### Scenario: A retired schema leaves no trace
@e2e exclude structural; covered by the per-retirement grep recorded in each PR

- **GIVEN** a schema is retired
- **WHEN** `lib/`, `src/` and `tests/` are searched for the slug
- **THEN** no reference SHALL remain
- **AND** the mock register SHALL hold no seed with that schema

#### Scenario: A schema promised by a shipped spec is surfaced, not retired
@e2e exclude structural; covered by the triage table and the allowlist's ownerChange field

- **GIVEN** a schema with no surface whose owning change shipped its storage
  and its seeds
- **WHEN** it is triaged
- **THEN** its fate SHALL be surface, with the owning change named
- **AND** it SHALL NOT be deleted on the strength of having no reader,
  because an instance may have been filling it since the change shipped

### Requirement: Catch-and-return-null sites are counted and ratcheted (REQ-QG-CRN-1)

A structural test SHALL list every `catch (\Throwable)` under `lib/Service/`
that returns `null` or `[]` within three statements and SHALL fail on any
site absent from a reason-bearing allowlist, on any allowlist entry without
a site, and when the count exceeds the recorded ceiling. Each entry SHALL
name its class: `refusal`, `degradation` or `read-miss`.

#### Scenario: A new swallowing catch fails the build
@e2e exclude structural; covered by ServiceCatchReturnsNullTest over a fixture file

- **GIVEN** a service method with a new `catch (\Throwable) { return null; }`
- **WHEN** the structural test runs
- **THEN** it SHALL fail naming the file and method

#### Scenario: The ceiling only goes down
@e2e exclude structural; same test, ceiling branch

- **GIVEN** an allowlist ceiling of 47 and 48 sites
- **WHEN** the test runs
- **THEN** it SHALL fail

### Requirement: A rule that refuses a write answers with a status (REQ-QG-CRN-2)

Every allowlisted site of class `refusal` SHALL be converted to throw a
typed exception that the controller translates to a 4xx with `{message,
error}`, `error` naming the rule, and its allowlist entry removed. Every
controller unit test of a guarded method SHALL assert the status code for
the pass and the refusal.

#### Scenario: A refused transition tells the caller
@e2e tests/e2e/refusal-status.spec.ts

- **GIVEN** a case in a status with no transition to Closed
- **WHEN** the transition is requested over the API
- **THEN** the response SHALL be 409 with `error` naming the rule
- **AND** the case status SHALL be unchanged

#### Scenario: Degradation stays, and says so
@e2e exclude covered by the allowlist entries of class degradation and the warning-log assertion in their unit tests

- **GIVEN** the engine is absent
- **WHEN** a timer call runs
- **THEN** a warning SHALL be logged naming the engine
- **AND** the domain flow SHALL continue on case data
