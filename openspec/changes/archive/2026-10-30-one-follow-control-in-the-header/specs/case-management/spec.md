# case-management delta: one-follow-control-in-the-header

## ADDED Requirements

### Requirement: The case page shows one Follow control (REQ-CM-40a)

`#CaseDetail` SHALL show exactly one Follow control: the platform's follow toggle
beside the case title, with its notifications switch while you follow. The banner
row SHALL NOT carry a second Follow control. The Follow row action on the case
lists and the Followers section on the People tab SHALL stay.

#### Scenario: The case page shows one Follow control
@e2e tests/e2e/case-followers.spec.ts

- **GIVEN** a case you do not follow
- **WHEN** you open the case page
- **THEN** there SHALL be one Follow control, beside the case title
- **AND** the banner row SHALL show no Follow control

#### Scenario: The bell belongs to the one control
@e2e tests/e2e/case-number-and-favourites.spec.ts

- **GIVEN** a case you follow
- **WHEN** you press the bell beside the Follow control and reload
- **THEN** the case SHALL still be followed
- **AND** the bell SHALL read notifications off
