# case-types delta: case-definition-packages

## ADDED Requirements

### Requirement: A procedure ships as a package on disk (REQ-CT-46)

The app SHALL install case definition packages from `lib/Settings/packages/<id>/`. A package SHALL carry a `package.json` manifest with `id` (equal to its directory name), `title`, `version` (major.minor.patch), `requires` (a list of capability names), `parts` (a non-empty list of `case-type`, `flows`, `seed`, `settings`) and optionally `install` (`auto`, the default, or `on-request`). Every row a package carries SHALL have a fixed UUID `id`, used once in the package. The installer SHALL refuse a package that breaks any of these rules, naming each break, and SHALL write nothing for it.

#### Scenario: An invalid package is refused with its errors
@e2e exclude the installer has no user interface; proven by tests/Unit/Service/CaseDefinition/PackageInstallerTest.php::testAnInvalidPackageIsRefusedWithItsErrors

- **GIVEN** a package whose manifest names another id, a version `1`, an unknown part, a row without an id and a seed file without rows
- **WHEN** the installer installs it
- **THEN** it SHALL answer `refused` and list each break
- **AND** it SHALL write no row

### Requirement: A package whose capability is missing is refused by name (REQ-CT-47)

The installer SHALL refuse a package when this instance lacks a capability the package requires, or one its parts imply, or an app it names as `app:<app id>`, before writing a row, and SHALL name every missing capability.

#### Scenario: A package needs a capability this instance does not have
@e2e exclude the installer has no user interface; proven by tests/Unit/Service/CaseDefinition/PackageInstallerTest.php::testAMissingCapabilityIsRefusedByName

- **GIVEN** a package that requires `term` and `app:decidiq`, on an instance that provides neither
- **WHEN** the installer installs it
- **THEN** it SHALL answer `refused` naming `term` and `app:decidiq`
- **AND** it SHALL write no row

### Requirement: A package is written whole or not at all (REQ-CT-48)

The installer SHALL write every row of a package in one run. When any row fails, or a part's schema is not configured, or a row cannot be recorded in the shipped-set ledger, it SHALL take back every row that run created, across all parts. It SHALL NOT write a package when the shipped-set ledger is not configured.

#### Scenario: A refused row takes the whole package back
@e2e exclude a store refusal cannot be provoked from the browser; proven by tests/Unit/Service/CaseDefinition/PackageInstallerTest.php::testARefusedRowTakesTheWholePackageBack

- **GIVEN** a package with a case type, statuses, a role, a flow and a seed row
- **AND** the store refuses the flow
- **WHEN** the installer installs it
- **THEN** it SHALL answer `error` with the store's reason
- **AND** no case type, status, role, flow, seed or ledger row from it SHALL remain

### Requirement: Every installed row is recorded, and a reinstall changes nothing (REQ-CT-49)

The installer SHALL record every row it writes in the shipped-set ledger, with the package id as the set and the package version as the set version. Installing a package at the version already recorded SHALL write nothing. Installing a newer version SHALL write the rows the instance does not hold and SHALL leave every row it already holds as it is. The `InstallShippedPackages` repair step SHALL install every package whose `install` is `auto`, on install and on upgrade; a package marked `on-request` SHALL install only when an administrator asks (`occ dossiq:package:install <id>`).

#### Scenario: A package installs and is recorded
@e2e exclude no user interface; proven by tests/Unit/Service/CaseDefinition/PackageInstallerTest.php::testAPackageWritesEveryRowAndRecordsEachInTheLedger and live by the packages workflow, which installs on a clean instance and reads the rows back

- **GIVEN** a clean instance and the fixture package
- **WHEN** the installer installs it
- **THEN** every row SHALL be readable from its schema
- **AND** every row SHALL have a ledger row naming the package and its version

#### Scenario: A newer version keeps a local edit
@e2e exclude no user interface; proven by tests/Unit/Service/CaseDefinition/PackageInstallerTest.php::testANewerVersionAddsNewRowsAndLeavesExistingOnesAlone

- **GIVEN** an installed package whose case type was edited here
- **WHEN** a newer version that adds a role installs
- **THEN** the role SHALL be written
- **AND** the edited case type SHALL keep the edit

### Requirement: A case type a seeder already wrote is adopted, not duplicated (REQ-CT-50)

A seeder wrote its case types under random ids; the package that replaces it ships fixed ids. When the installer meets a case type row whose id this instance does not hold, it SHALL look for a case type with the same `identifier`, and when one exists it SHALL adopt it: write nothing, record the existing row in the ledger under the package, and point every later row of the package that names the package's id (a plain field or inside a JSON string such as a flow's transitions) at the existing id. A row of an adopted case type SHALL be adopted when the case type holds a row of that schema with the same identifier, name, title or key, in that order; otherwise it SHALL be written, pointing at the adopted case type. A failed install SHALL never remove an adopted row. An adopted row SHALL be recorded once per package, so a newer version does not reset its fingerprint (decision 190).

#### Scenario: An existing case type is adopted and nothing is written
@e2e exclude no user interface; proven by tests/Unit/Service/CaseDefinition/PackageInstallerTest.php::testAnExistingCaseTypeIsAdoptedByIdentifierAndNothingIsWritten

- **GIVEN** an instance where a seeder wrote the fixture case type, its statuses, role, flow and property under random ids
- **WHEN** the installer installs the fixture package
- **THEN** no row SHALL be written, changed or removed
- **AND** each existing row SHALL have a ledger row naming the package and its version

#### Scenario: An adopted case type gets only the rows it lacks
@e2e exclude no user interface; proven by tests/Unit/Service/CaseDefinition/PackageInstallerTest.php::testAnAdoptedCaseTypeGetsOnlyTheRowsItLacksPointingAtItsOwnIds

- **GIVEN** an instance where a seeder wrote only the fixture case type and its statuses
- **WHEN** the installer installs the fixture package
- **THEN** the role, flow and property SHALL be written pointing at the existing case type
- **AND** the flow's transitions SHALL name the existing statuses

#### Scenario: Another identifier is not adopted
@e2e exclude no user interface; proven by tests/Unit/Service/CaseDefinition/PackageInstallerTest.php::testACaseTypeWithAnotherIdentifierIsNotAdopted

- **GIVEN** a case type with the same title but another identifier
- **WHEN** the installer installs the fixture package
- **THEN** the package's case type SHALL be written beside it
