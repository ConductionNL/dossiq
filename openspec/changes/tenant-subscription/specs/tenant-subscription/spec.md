# tenant-subscription Delta

## ADDED Requirements

### Requirement: A tenant has a subscription with a package and a reference

An organisation on this environment SHALL be able to carry one subscription record naming its package and contract
reference, readable by admin and beheerders only, and the organisation page SHALL show both in its Status card.

#### Scenario: DqTenant

- **GIVEN** Gemeente Waterland with the subscription Standaard, TEN-0014
- **THEN** its Status card reads "Pakket Standaard" and "Kenmerk van het contract TEN-0014"
