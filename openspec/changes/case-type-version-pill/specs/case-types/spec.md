# case-types Delta

## ADDED Requirements

### Requirement: The case type pill names its version

A case type page's status pill SHALL read "Versie {version} gepubliceerd" for a published version and "Versie
{version} concept" for a draft.

#### Scenario: A draft fifth version

- **GIVEN** case type version 5, a draft
- **THEN** the pill beside the domain reads "Versie 5 concept" in amber
