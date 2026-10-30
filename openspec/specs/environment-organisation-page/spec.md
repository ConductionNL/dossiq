# environment-organisation-page Specification

## Purpose
What dossiq does for environment organisation page. The requirements were first written by the change `r21-tenant-history-title` (archived 2026-10-30 under `openspec/changes/archive/2026-10-30-r21-tenant-history-title/`) and cover: The organisation history card reads Historie.

## Requirements

### Requirement: The organisation history card reads Historie

The `organisation-side-history` card on `EnvironmentOrganisationDetail` SHALL be titled with a string whose Dutch
translation is "Historie".

#### Scenario: Organisation page side column

- **GIVEN** an organisation page under Organisations on this environment, in Dutch
- **THEN** the history card is titled "Historie", as on board DqTenant
