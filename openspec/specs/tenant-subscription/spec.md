# tenant-subscription Specification

## Purpose
What dossiq does for tenant subscription. The requirements were first written by the change `tenant-subscription` (archived 2026-10-30 under `openspec/changes/archive/2026-10-30-tenant-subscription/`) and cover: A tenant has a subscription with a package and a reference; The Quota card says when another package takes effect.

## Requirements

### Requirement: A tenant has a subscription with a package and a reference

An organisation on this environment SHALL be able to carry one subscription record naming its package and contract
reference, readable by admin and beheerders only. The organisation page SHALL end its "Historie" side card with the
reference, and SHALL leave that line out for an organisation without a subscription.

#### Scenario: DqTenant

- **GIVEN** Gemeente Waterland with the subscription Standaard, TEN-0014
- **THEN** its "Historie" side card ends with "Kenmerk TEN-0014"

### Requirement: The Quota card says when another package takes effect

The organisation page's Quota card SHALL end with a line saying that another package takes effect within a quarter of
an hour, the longest a signed-in session keeps the organisation's quota.

#### Scenario: DqTenant Quota

- **GIVEN** the Quota card of Gemeente Waterland
- **THEN** it ends with "Een ander pakket gaat binnen een kwartier in."
