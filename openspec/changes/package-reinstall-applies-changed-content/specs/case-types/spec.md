# Case types

## MODIFIED Requirements

### Requirement: Every installed row is recorded, and a reinstall changes nothing (REQ-CT-49)

The installer SHALL record every row it writes in the shipped-set ledger, with the package id as the set and the package version as the set version, and SHALL record the package itself with its version and a digest of its files. Installing a package at the version already recorded, with the digest already recorded, SHALL write nothing. Installing a newer version, or the same version whose files differ from the recorded digest, or a package whose record carries no digest, SHALL write the rows the instance does not hold. For every row it already holds in dossiq's register, after the write and the ledger record succeeded, it SHALL write the shipped version when the ledger records the row under this package and the row still hashes to its stamp, and SHALL stamp it with the version; it SHALL keep a row edited here, a row another set or nobody shipped, and a row it cannot read, and SHALL log each kept row by id. A row whose content already is what the package ships SHALL not be written. The outcome SHALL report how many rows were refreshed and the ids of the rows kept. The `InstallShippedPackages` repair step SHALL install every package whose `install` is `auto`, on install and on upgrade; a package marked `on-request` SHALL install only when an administrator asks (`occ dossiq:package:install <id>`).

#### Scenario: A package installs and is recorded
@e2e exclude no user interface; proven by tests/Unit/Service/CaseDefinition/PackageInstallerTest.php::testAPackageWritesEveryRowAndRecordsEachInTheLedger and live by the packages workflow, which installs on a clean instance and reads the rows back

- **GIVEN** a clean instance and the fixture package
- **WHEN** the installer installs it
- **THEN** every row SHALL be readable from its schema
- **AND** every row SHALL have a ledger row naming the package and its version

#### Scenario: The same version with changed content
@e2e exclude no user interface; proven by tests/Unit/Service/CaseDefinition/PackageInstallerTest.php::testTheSameVersionWithChangedContentRefreshesUntouchedRows

- **GIVEN** an installed package nobody edited here
- **WHEN** the same version, with a changed case type description, installs
- **THEN** the outcome SHALL be `installed`, not `current`
- **AND** the case type SHALL hold the description the package now ships
- **AND** a third install SHALL be `current` and write nothing

#### Scenario: A record from before the digest
@e2e exclude no user interface; proven by tests/Unit/Service/CaseDefinition/PackageInstallerTest.php::testARecordWithoutADigestInstallsOnceMore

- **GIVEN** a package recorded at its version without a digest
- **WHEN** the same version installs
- **THEN** the outcome SHALL be `installed` and write no row
- **AND** the next install SHALL be `current`

## ADDED Requirements

### Requirement: An operator can take a package's keys over a local edit (REQ-CT-57)

`occ dossiq:package:install <id> --over-local-changes` SHALL install as above, and for a row the ledger records under this package that was changed here SHALL write every key the package ships and keep the value of every key it does not ship, and SHALL stamp the row with the version. A row another set or nobody shipped SHALL still be kept. Without the option an edited row SHALL be kept. The option SHALL apply even when the version and digest are current.

#### Scenario: An edited case type takes the package's keys
@e2e exclude no user interface; proven by tests/Unit/Service/CaseDefinition/PackageInstallerTest.php::testOverLocalChangesTakesThePackagesKeysAndKeepsTheRest

- **GIVEN** an installed package whose case type title was edited here and that holds a local key the package does not ship
- **WHEN** the same version installs with `--over-local-changes`
- **THEN** the case type SHALL hold the title the package ships
- **AND** the local key SHALL keep its value
- **AND** the outcome SHALL not name the case type as kept
