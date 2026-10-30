# example-set-contacts-companies

## ADDED Requirements

### Requirement: The example data carries the contacts and companies the list boards draw

The Zuiddrecht example set SHALL hold `brpPerson` records for Pieter Bos and Henk Visser, and `kvkCompany` records for Visser Bouw B.V. and Aannemersbedrijf Van Wijk B.V., each quoting its cast entry.

#### Scenario: The organisation list
- **GIVEN** the set is installed
- **WHEN** a handler opens Organisations filtered on Zuiddrecht
- **THEN** Aannemersbedrijf Van Wijk B.V. SHALL be in the list
