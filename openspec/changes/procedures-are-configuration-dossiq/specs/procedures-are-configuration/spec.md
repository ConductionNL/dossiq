## ADDED Requirements

### Requirement: dossiq ships procedures as packages (REQ-DQPAC-001)

dossiq MUST ship each procedure it seeds as a case definition package under `lib/Settings/packages/<package-id>/`, following hydra REQ-PAC-001. dossiq MUST NOT add a seeder class per procedure. The package installer MUST be the only code that writes a procedure's case type, flows, terms, notices and seed rows.

#### Scenario: The bezwaar case type comes from its package

- **GIVEN** a clean instance with dossiq installed
- **WHEN** the package `bezwaar` is installed
- **THEN** the case type, its statuses, its roles and its workflow exist in the register
- **AND** the shipped-set ledger lists each row as `shipped` with package `bezwaar`
- @e2e exclude package install, covered by the package install CI job and the bezwaar Newman collection

### Requirement: A moved procedure has no unit tests of its own (REQ-DQPAC-002)

When a lane moves a procedure onto a package, it MUST delete that procedure's PHPUnit tests in the same pull request, after a Newman collection and a Playwright journey for the package pass. The generic capability it uses MUST carry the unit tests instead.

#### Scenario: Bezwaar moves

- **GIVEN** `tests/Unit/Service/Bezwaar/*` exists
- **WHEN** lane R7 moves bezwaar onto its package
- **THEN** `tests/integration/packages/bezwaar.postman_collection.json` and a bezwaar Playwright journey exist and pass
- **AND** `tests/Unit/Service/Bezwaar/` is gone
- @e2e exclude process rule, checked in review
