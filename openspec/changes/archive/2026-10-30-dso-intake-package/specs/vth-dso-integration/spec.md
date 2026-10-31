# vth-dso-integration delta: dso-intake-package

## MODIFIED Requirements

### Requirement: DSO intake is on by default with integriq

The system SHALL ship the settings-only case definition package `dso-intake`, installed automatically on install and on upgrade. Its setting names integriq's `dso_verzoek` schema by register (`integriq`, or the old `openconnector`) and slug, and the installer SHALL store that schema's id on this instance in `dso_vergunningaanvraag_schema` when the key was never set. A value an administrator set, an empty value included, SHALL NOT be overwritten. When integriq or its `dso_verzoek` schema is missing, the package SHALL be refused naming integriq and SHALL be tried again on the next upgrade. While the key is empty, the administration overview SHALL warn that DSO intake is off.

#### Scenario: Fresh install with integriq
@e2e tests/e2e/packages/dso-intake.spec.ts

- **GIVEN** an instance with integriq, where the key was never set
- **WHEN** dossiq is installed or upgraded
- **THEN** the administration overview SHALL report that DSO intake reads a schema
- **AND** that schema SHALL be integriq's `dso_verzoek`

#### Scenario: An administrator turned intake off
@e2e exclude a settings precedence rule of the installer; proven by tests/Unit/Service/CaseDefinition/PackageInstallerTest.php::testSettingsNeverOverruleAnAdministratorsValue

- **WHEN** an administrator set the key to an empty value and dossiq is upgraded
- **THEN** the key SHALL stay empty

#### Scenario: integriq is not installed
@e2e exclude an instance without integriq; proven by tests/Unit/Service/CaseDefinition/PackageInstallerTest.php::testAReferenceToAnAppThatIsNotInstalledRefusesThePackage

- **WHEN** dossiq is installed without integriq
- **THEN** the package SHALL be refused naming integriq, the key SHALL stay absent, and the setup check SHALL warn that DSO intake is off
