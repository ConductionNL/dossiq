# organisation-history-tab Delta

**Status**: proposed
**Scope**: dossiq
**OpenSpec changes**:

- [organisation-history-tab](../../)

## Purpose

An organisation's page shows what happened to it.

## ADDED Requirements

### Requirement: An organisation shows its history

`EnvironmentOrganisationDetail` SHALL have a Historie tab (Record history) after Billing that lists the organisation's audit-trail entries
with action `organisation.status_changed` or `organisation.updated`, newest first, each with the user and the date.

#### Scenario: A suspended organisation

- **GIVEN** an organisation an administrator suspended
- **THEN** its Historie tab lists "Status gewijzigd" with the administrator's name and the date
