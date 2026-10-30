## ADDED Requirements

### Requirement: A household is closed to administrators outside the sensitive group

The household endpoints SHALL answer only to a reader in `dossiq-sensitive` who
may read the case. Administering the instance SHALL NOT open a household.

#### Scenario: an administrator outside the group
- GIVEN an instance administrator who is not in `dossiq-sensitive`
- WHEN they ask for a case's household cases or look the household up
- THEN they are refused with 403 `household-not-allowed`
