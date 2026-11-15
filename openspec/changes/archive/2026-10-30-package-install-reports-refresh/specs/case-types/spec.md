# case-types Delta

## ADDED Requirements

### Requirement: A package install reports what its refresh did

`occ dossiq:package:install` SHALL print how many rows already present were refreshed and which were kept.

#### Scenario: jeugdwet over local changes

- **THEN** the output reads "2 row(s) refreshed, 1 kept as this instance holds them: c-1"
