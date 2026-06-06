# Quality Gates — Delta

## ADDED Requirements

### Requirement: Unified strict quality gate

Procest SHALL expose a single unified quality gate, `composer check:strict`,
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

### Requirement: PHPStan baseline is documented and minimal

The PHPStan gate MAY ship a baseline (`phpstan-baseline.neon`), but it SHALL
contain only tracked, documented debt and SHALL carry a header explaining each
remaining category and where it is owned. Stub-precision false positives SHALL
be expressed as documented `ignoreErrors` patterns in `phpstan.neon`, not as
opaque baseline entries.

#### Scenario: Baseline header documents remaining debt

- **WHEN** a developer opens `phpstan-baseline.neon`
- **THEN** a header comment explains the single remaining category
  (injected-but-unused dependencies) and the work that will remove it
- **AND** PHPStan analysis reports `[OK] No errors` with the baseline applied

### Requirement: CI uses a served Codeberg runner

The `pre-merge-check-strict` workflow SHALL target a served Codeberg runner
label (`codeberg-small`) and SHALL NOT use the unserved `docker` label, so the
gate is actually scheduled and executed on pull requests.

#### Scenario: Workflow targets codeberg-small

- **WHEN** the `pre-merge-check-strict` workflow is triggered by a pull request
- **THEN** its job declares `runs-on: codeberg-small`
- **AND** the job is scheduled and runs `composer check:strict`
