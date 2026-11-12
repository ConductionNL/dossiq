# case-types Delta

## MODIFIED Requirements

### Requirement: Status and result names are strong text, a status with its colour dot

The Statussen and Resultaten tables SHALL draw their names as links without an underline (14px, the link colour), the
Statussen names with a dot in the status colour, and the archival action in sentence case.

#### Scenario: DqZaaktypeStatussen

- **WHEN** an administrator opens the Statussen tab
- **THEN** "Ontvangen" has a blue dot and reads in the link colour without an underline, and a result's archival
  action reads "Vernietigen"
