# example-set-contacts-companies Specification

## Purpose
What dossiq does for example set contacts companies. The requirements were first written by the change `example-set-contacts-companies` (archived 2026-10-30 under `openspec/changes/archive/2026-10-30-example-set-contacts-companies/`) and cover: The example data carries the contacts and companies the list boards draw.

## Requirements

### Requirement: The example data carries the contacts and companies the list boards draw

The Zuiddrecht example set SHALL hold `brpPerson` records for Pieter Bos and Henk Visser, and `kvkCompany` records for Visser Bouw B.V. and Aannemersbedrijf Van Wijk B.V., each quoting its cast entry.

#### Scenario: The organisation list
- **GIVEN** the set is installed
- **WHEN** a handler opens Organisations filtered on Zuiddrecht
- **THEN** Aannemersbedrijf Van Wijk B.V. SHALL be in the list
