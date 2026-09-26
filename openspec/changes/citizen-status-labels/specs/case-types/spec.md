## ADDED Requirements

### Requirement: A status has a label the applicant reads (REQ-CT-25)

`statusType` SHALL carry optional `publicLabel` and `publicDescription`.
Every citizen-facing surface SHALL show `publicLabel`, or `name` when it is
empty, and `publicDescription` when set and nothing at all when it is not:
`#PublicStatus`, the portal contribution, and an OpenRegister access link
minted on the case. The case SHALL carry the resolved words as
`statusPublicLabel` and `statusPublicDescription`, because those surfaces are
handed the case and cannot follow its status reference. The ZGW mapping SHALL
write `publicLabel` to `statustype.statustekst`. The status editor on the case
type page SHALL offer both fields.

#### Scenario: The applicant reads the public label
@e2e tests/e2e/citizen-status-labels.spec.ts

- **GIVEN** a status "Toets register B" with public label "We check your application"
- **WHEN** somebody with no account opens a link to a case in that status
- **THEN** they SHALL read "We check your application", and neither the status
  name nor its internal description

#### Scenario: No label, no change
@e2e tests/e2e/citizen-status-labels.spec.ts

- **GIVEN** a status without a public label
- **WHEN** a case in that status is read through a link
- **THEN** it SHALL show the status name, and no description at all

#### Scenario: The author sets it on the status row
@e2e tests/e2e/case-type-status-authoring.spec.ts

- **GIVEN** the status editor of a case type
- **WHEN** you open a status
- **THEN** Public label and Public description SHALL be offered
