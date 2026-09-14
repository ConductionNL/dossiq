## ADDED Requirements

### Requirement: A status has a label the applicant reads (REQ-CT-25)

`statusType` SHALL carry optional `publicLabel` and `publicDescription`.
`#PublicStatus` and the portal contribution SHALL show `publicLabel`, or
`name` when it is empty, and `publicDescription` when set. The ZGW mapping
SHALL write `publicLabel` to `statustype.statustekst`. The status editor on
the case type page SHALL offer both fields.

#### Scenario: The applicant reads the public label
@e2e tests/e2e/citizen-status-labels.spec.ts

- **GIVEN** a status "Toets register B" with public label "We check your application"
- **WHEN** an applicant opens the public status page of a case in that status
- **THEN** the page SHALL read "We check your application"

#### Scenario: No label, no change
@e2e tests/e2e/citizen-status-labels.spec.ts

- **GIVEN** a status without a public label
- **WHEN** the public status page is opened
- **THEN** the page SHALL show the status name

#### Scenario: The author sets it on the status row
@e2e tests/e2e/case-type-status-authoring.spec.ts

- **GIVEN** the status editor of a case type
- **WHEN** you open a status
- **THEN** Public label and Public description SHALL be offered
