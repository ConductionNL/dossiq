## ADDED Requirements

### Requirement: An adopted case type gets the fields it lacks and keeps the ones it has (REQ-CT-ADOPT-01)

When a package adopts a case type the instance already holds, the installer SHALL add each case-type field the package ships that the stored row does not have (no such key, or null), with references pointed at this instance's ids. It SHALL NOT change a field the stored row has, an empty one included. The fields SHALL be written only after the install is recorded, so a failed install leaves the adopted row as it was. Other adopted rows (statuses, roles, results, flows) SHALL stay as they are.

#### Scenario: A seeded case type gets the record import the package adds

- **GIVEN** an instance holding the case type `wmo-melding` without `recordImports`, written by the register seed
- **WHEN** the Wmo-melding package is installed or upgraded
- **THEN** the stored case type SHALL carry the package's `recordImports`
- **AND** its title and every other field it already had SHALL be unchanged
- @e2e exclude installer behaviour, asserted in PHPUnit

#### Scenario: A field the administrator emptied stays empty

- **GIVEN** an adopted case type whose `description` is an empty string
- **WHEN** a package that ships a description is installed
- **THEN** the description SHALL stay empty
- @e2e exclude installer behaviour, asserted in PHPUnit
