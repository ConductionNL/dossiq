# case-types Delta

## ADDED Requirements

### Requirement: A package install reaches the rows it shipped, whoever runs it

A package install SHALL read and write the rows it ships past the caller's access check, so a CLI run with no user refreshes a case it shipped earlier. A plain install SHALL still keep a row changed here, and only `--over-local-changes` SHALL let the package's keys replace it.

#### Scenario: jeugdwet 1.12.0 over local changes from occ

- **GIVEN** case e0b306d2 was shipped by jeugdwet 1.0.0 without `legalBasis`
- **WHEN** `occ dossiq:package:install jeugdwet --over-local-changes` runs
- **THEN** the case reads `legalBasis` "Jeugdwet artikel 2.3" and `initiatorDisplayName` "Huisarts, met toestemming van de ouders"

#### Scenario: a plain install keeps a local edit

- **GIVEN** the case's assignee was changed here
- **WHEN** the package is installed without `--over-local-changes`
- **THEN** the case is kept as it is
