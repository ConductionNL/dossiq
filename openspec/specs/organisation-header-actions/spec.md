# organisation-header-actions Specification

## Purpose
An organisation's page offers the lifecycle actions the board draws.

## Requirements

### Requirement: An organisation can be suspended, resumed and ended from its page

`EnvironmentOrganisationDetail` SHALL offer Suspend while the organisation is active and Resume while it is suspended,
both in the header, and End the organisation under More with a confirmation. Each SHALL call OpenRegister's organisation
lifecycle endpoint for the organisation (`suspend`, `activate`, `deprovision`) with PUT.

#### Scenario: An active organisation

- **GIVEN** an active organisation's page
- **THEN** the header shows "Opschorten", and "Beëindigen" is under Meer, as on board DqTenant
