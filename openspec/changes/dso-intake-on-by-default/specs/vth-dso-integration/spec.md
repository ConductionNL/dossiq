## ADDED Requirements

### Requirement: DSO intake is on by default with integriq

When integriq is installed and `dso_vergunningaanvraag_schema` was never set, the system SHALL set it to the id of integriq's `dso_verzoek` schema on install and on upgrade, found through integriq's register by slug. A value an administrator set, an empty value included, SHALL NOT be overwritten. While the key is empty, the administration overview SHALL warn that DSO intake is off.

@e2e exclude repair step and setup check; covered by PHPUnit and the live install run, no app page

#### Scenario: Fresh install with integriq

- **WHEN** dossiq is installed or upgraded, integriq is installed and the key is absent
- **THEN** the key SHALL hold the id of integriq's `dso_verzoek` schema, and a mapped verzoek SHALL become a case

#### Scenario: An administrator turned intake off

- **WHEN** an administrator set the key to an empty value and dossiq is upgraded
- **THEN** the key SHALL stay empty

#### Scenario: integriq is not installed

- **WHEN** dossiq is installed without integriq
- **THEN** the key SHALL stay absent, and the setup check SHALL warn that DSO intake is off
