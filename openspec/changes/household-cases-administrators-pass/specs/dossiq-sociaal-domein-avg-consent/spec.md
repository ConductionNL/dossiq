## ADDED Requirements

### Requirement: Who may read a case's household

The household endpoints SHALL answer to a reader who is in `dossiq-sensitive`
or administers the instance, AND who may read the case. Anyone else SHALL be
refused with `household-not-allowed`.

#### Scenario: an administrator outside the sensitive group
- GIVEN an administrator who is not in `dossiq-sensitive` and may read the case
- WHEN they ask for the case's household cases
- THEN the household's cases are returned

#### Scenario: an administrator who may not read the case
- GIVEN an administrator who may not read the case
- WHEN they ask for its household cases
- THEN they are refused
