# Case types

## MODIFIED Requirements

### Requirement: Every installed row is recorded, and a reinstall changes nothing (REQ-CT-49)

The installer SHALL record every row it writes in the shipped-set ledger, with the package id as the set and the package version as the set version, and SHALL record the package itself with its version and a digest of its files. Installing a package at the version already recorded, with the digest already recorded, SHALL write nothing. Installing a newer version, or the same version whose files differ from the recorded digest, or a package whose record carries no digest, SHALL write the rows the instance does not hold. For every row it already holds in dossiq's register, after the write and the ledger record succeeded, it SHALL write the shipped version when the ledger records the row under this package and the row still hashes to its stamp, and SHALL stamp it with the version; it SHALL keep a row edited here, a row another set or nobody shipped, and a row it cannot read, and SHALL log each kept row by id. A row whose content already is what the package ships SHALL not be written. The outcome SHALL report how many rows were refreshed and the ids of the rows kept. The `InstallShippedPackages` repair step SHALL, on install and on upgrade, install every package whose `install` is `auto` and every package already installed here whatever its `install` mode; a package marked `on-request` that was never installed SHALL install only when an administrator asks (`occ dossiq:package:install <id>`).

#### Scenario: An upgrade updates an installed on-request package
@e2e exclude no user interface; proven by tests/Unit/Repair/InstallShippedPackagesTest.php::testTheUpgradeUpdatesEveryInstalledPackage and tests/Unit/Service/CaseDefinition/PackageInstallerTest.php::testAReloadUpdatesAnInstalledOnRequestPackageAndKeepsAnEdit

- **GIVEN** an on-request package installed at 1.0.0, with one row edited here
- **WHEN** the app upgrades and ships version 1.1.0 of it
- **THEN** the repair step SHALL install 1.1.0
- **AND** the rows nobody edited SHALL hold what 1.1.0 ships
- **AND** the edited row SHALL keep the edit

#### Scenario: An on-request package nobody installed
@e2e exclude no user interface; proven by tests/Unit/Service/CaseDefinition/PackageInstallerTest.php::testAReloadInstallsWhatIsInstalledOrAutomaticAndNothingElse

- **GIVEN** an on-request package never installed here
- **WHEN** the repair step runs
- **THEN** nothing of it SHALL be written
