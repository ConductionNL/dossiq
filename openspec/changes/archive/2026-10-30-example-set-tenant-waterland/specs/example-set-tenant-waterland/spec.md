# example-set-tenant-waterland

## ADDED Requirements

### Requirement: The tenant page has a tenant with an administrator, mandates and billing

The Zuiddrecht example set SHALL give the organisation Gemeente Waterland:
- a `tenantUser` record for Marieke Visser as administrator;
- three `tenantMandate` records she signed;
- a `case_created` and a `case_refund` billing event.

#### Scenario: The tenant page
- **GIVEN** the set is installed
- **WHEN** an administrator opens Gemeente Waterland under Organisations on this environment
- **THEN** the page SHALL list Marieke as administrator, three mandates signed by her and two billing lines
