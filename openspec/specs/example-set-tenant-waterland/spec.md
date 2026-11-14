# example-set-tenant-waterland Specification

## Purpose
What dossiq does for example set tenant waterland. The requirements were first written by the change `example-set-tenant-waterland` (archived 2026-10-30 under `openspec/changes/archive/2026-10-30-example-set-tenant-waterland/`) and cover: The tenant page has a tenant with an administrator, mandates and billing.

## Requirements

### Requirement: The tenant page has a tenant with an administrator, mandates and billing

The Zuiddrecht example set SHALL give the organisation Gemeente Waterland:
- a `tenantUser` record for Marieke Visser as administrator;
- three `tenantMandate` records she signed;
- a `case_created` and a `case_refund` billing event.

#### Scenario: The tenant page
- **GIVEN** the set is installed
- **WHEN** an administrator opens Gemeente Waterland under Organisations on this environment
- **THEN** the page SHALL list Marieke as administrator, three mandates signed by her and two billing lines
