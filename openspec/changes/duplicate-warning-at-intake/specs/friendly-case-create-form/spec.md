## ADDED Requirements

### Requirement: The create form warns about likely duplicates (REQ-FCF-10)

The `case` schema SHALL declare dedup rules (requester and type within 30
days; address and type; subject similarity). The New case form SHALL show
the platform's matches as a warning with links before save.
`caseType.duplicatePolicy` SHALL be `warn` or `block`; under `block` only
`dossiq-coordinators` may continue, with a reason recorded on the case.

#### Scenario: A likely duplicate is shown
@e2e tests/e2e/duplicate-warning.spec.ts

- **GIVEN** an open case for requester Jan of type Kapvergunning filed this week
- **WHEN** you start a new Kapvergunning for Jan
- **THEN** the form SHALL show the open case as a likely duplicate with a link

#### Scenario: Block stops a handler, not a coordinator
@e2e tests/e2e/duplicate-warning.spec.ts

- **GIVEN** the case type's policy is block and a match is shown
- **WHEN** a handler tries to save
- **THEN** Save SHALL be disabled
- **AND** a coordinator SHALL be offered Continue anyway with a reason
