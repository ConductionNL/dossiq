# organisation-edit Delta

**Status**: proposed
**Scope**: dossiq
**OpenSpec changes**:

- [organisation-edit](../../)

## Purpose

An organisation's name and description can be edited from its page.

## ADDED Requirements

### Requirement: An organisation is edited from its page

`EnvironmentOrganisationDetail` SHALL offer Edit in its header: an `open-form` action that reads the organisation from
`/apps/openregister/api/organisations/{uuid}` and saves its name and description there with PATCH.

#### Scenario: Rename an organisation

- **GIVEN** an organisation's page
- **WHEN** the administrator picks "Bewerken", changes the name and saves
- **THEN** OpenRegister stores the new name and the page shows it
