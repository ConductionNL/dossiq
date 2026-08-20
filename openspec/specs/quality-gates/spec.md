---
status: done
status-note: Reverse-synced 2026-06-13 from an archived fully-implemented change; capability code confirmed present on development.
---
# quality-gates Specification

## Purpose
Defines the unified strict quality gate for procest: a single `composer check:strict` command that runs lint, PHPCS, PHPMD, Psalm, and PHPStan in sequence and fails on any violation, run on every pull request to `development`, `main`, or `beta`. It requires PHPMD to run with no baseline, keeps any PHPStan baseline minimal and documented, and pins the CI workflow to a served Codeberg runner so the gate is actually scheduled.
## Requirements
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

