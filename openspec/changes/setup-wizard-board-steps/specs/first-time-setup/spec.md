# first-time-setup Delta: setup-wizard-board-steps

**Status**: draft
**Scope**: the setup wizard's steps follow the DqInstellen board.

## ADDED Requirements

### Requirement: The wizard follows the board's steps

The setup wizard SHALL offer, in this order: Organisation, House style, Case types, Example data, the dwangsom callback secret, and Done. `GET /api/setup/status` SHALL report each of them.

#### Scenario: The status names the board's steps
@e2e exclude the step contract is the status payload; covered by `tests/Unit/Controller/SetupControllerStatusTest.php::testEveryActionableManifestStepIsReported` and `::testTheBoardStepsAreReported`

- **WHEN** an administrator's wizard asks for the setup status
- **THEN** `steps` holds `organisation-details`, `house-style`, `case-types`, `demo-data`, `dwangsom-secret` and `done`

### Requirement: The organisation's name and logo live on the organisation

The Organisation step SHALL write the name and logo address onto the active OpenRegister Organisation and SHALL NOT store them in dossiq's app config. A blank field SHALL keep the current value. A value that cannot be written SHALL be reported, not dropped.

#### Scenario: A name is saved onto the organisation
@e2e exclude a write to the shared instance's organisation is a data write; covered by `tests/Unit/Controller/SetupControllerStatusTest.php::testOrganisationFieldsAreWrittenToTheOrganisation` and `tests/Unit/Service/Setup/ActiveOrganisationProfileTest.php::testABlankFieldKeepsTheCurrentValue`

- **GIVEN** the active organisation is called "Default Organisation"
- **WHEN** the administrator enters "Gemeente Waterland" and leaves the logo blank
- **THEN** the organisation is called "Gemeente Waterland" and keeps its logo
- **AND** no app-config value is written

### Requirement: The house style is handed to Thematiq

The House style step SHALL require the Thematiq app. Where Thematiq is not installed, the wizard SHALL leave the step out and the summary SHALL name it as skipped.

#### Scenario: Without Thematiq the step is skipped
@e2e exclude the skip is CnSetupWizard's `requires` behaviour, tested in nextcloud-vue; the declaration is checked by `tests/validate-manifest.js`

- **GIVEN** Thematiq is not installed
- **WHEN** the administrator opens the wizard
- **THEN** the House style step is not offered and the summary says it needs Thematiq

### Requirement: The case types step installs on-request packages

The Case types step SHALL offer a "None for now" card and one card per valid on-request case definition package. A card's Load button SHALL install that package and record the choice; a refused install SHALL store nothing. A package the step does not offer SHALL be refused before the installer runs.

#### Scenario: A card installs its package
@e2e exclude installing a package writes case types to the shared instance; covered by `tests/Unit/Controller/SetupControllerStatusTest.php::testACaseTypeCardInstallsItsPackage` and `tests/Unit/Service/Setup/CaseTypePackageChoiceTest.php`

- **WHEN** the administrator presses Load on the Subsidie card
- **THEN** the subsidie package is installed and the step is done
