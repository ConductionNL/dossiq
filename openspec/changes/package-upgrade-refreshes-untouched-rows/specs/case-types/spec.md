# Case types

## MODIFIED Requirements

### Requirement: Every installed row is recorded, and a reinstall changes nothing (REQ-CT-49)

The installer SHALL record every row it writes in the shipped-set ledger, with the package id as the set and the package version as the set version. Installing a package at the version already recorded SHALL write nothing. Installing a newer version SHALL write the rows the instance does not hold. For every row it already holds in dossiq's register, after the write and the ledger record succeeded, it SHALL write the newer version when the ledger records the row under this package and the row still hashes to its stamp, and SHALL stamp it with the newer version; it SHALL keep a row edited here, a row another set or nobody shipped, and a row it cannot read, and SHALL log each kept row by id. A row whose content already is what the newer version ships SHALL not be written. The outcome SHALL report how many rows were refreshed and the ids of the rows kept. The `InstallShippedPackages` repair step SHALL install every package whose `install` is `auto`, on install and on upgrade; a package marked `on-request` SHALL install only when an administrator asks (`occ dossiq:package:install <id>`).

#### Scenario: A package installs and is recorded
@e2e exclude no user interface; proven by tests/Unit/Service/CaseDefinition/PackageInstallerTest.php::testAPackageWritesEveryRowAndRecordsEachInTheLedger and live by the packages workflow, which installs on a clean instance and reads the rows back

- **GIVEN** a clean instance and the fixture package
- **WHEN** the installer installs it
- **THEN** every row SHALL be readable from its schema
- **AND** every row SHALL have a ledger row naming the package and its version

#### Scenario: A newer version keeps a local edit
@e2e exclude no user interface; proven by tests/Unit/Service/CaseDefinition/PackageInstallerTest.php::testANewerVersionAddsNewRowsAndLeavesExistingOnesAlone and ::testANewerVersionKeepsARowEditedHereAndNamesIt

- **GIVEN** an installed package whose case type was edited here
- **WHEN** a newer version that adds a role and changes the case type installs
- **THEN** the role SHALL be written
- **AND** the edited case type SHALL keep the edit
- **AND** the outcome SHALL name the case type as kept

#### Scenario: A newer version refreshes the rows nobody edited
@e2e exclude no user interface; proven by tests/Unit/Service/CaseDefinition/PackageInstallerTest.php::testANewerVersionRefreshesRowsNobodyEdited

- **GIVEN** an installed package nobody edited here
- **WHEN** a newer version that changes the case type description and renames a status installs
- **THEN** the case type and the status SHALL hold what the newer version ships
- **AND** their ledger rows SHALL name the newer version
- **AND** a row the newer version did not change SHALL not be written

### Requirement: A row the store filled in is still untouched

A package upgrade SHALL refresh a row whose keys the package ships are as the
package shipped them, whatever properties the store filled in on save, and
SHALL keep the values of keys the package does not ship. A row with a shipped
key edited here SHALL be kept.

#### Scenario: Properties filled in on save
- GIVEN a case type the store saved with properties the package never named
- WHEN a newer version of the package is installed
- THEN the case type takes the newer version

#### Scenario: A field filled in here
- GIVEN a case type with a field set here that the package does not ship
- WHEN a newer version of the package is installed
- THEN the case type takes the newer version and keeps that field's value
