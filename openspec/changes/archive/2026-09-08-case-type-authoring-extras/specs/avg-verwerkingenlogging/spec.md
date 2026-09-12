## ADDED Requirements

### Requirement: You record per case type which personal data it processes (REQ-AVG-01)

You record per case type which personal data it processes and on what
basis. The `caseType` schema SHALL carry `processesPersonalData`,
`personalDataCategories` over the eleven AVG categories, `legalBasis` over
OpenRegister's article 6 vocabulary, and `verwerkingsactiviteit`, the code of
the activity in the verwerkingsregister. The case type page SHALL show them
in a Personal data block.

**Feature tier**: MVP

#### Scenario: The block reads back what you saved
@e2e tests/e2e/case-type-authoring-extras.spec.ts

- **GIVEN** the type Bezwaar
- **WHEN** you mark it as processing personal data with the categories naw and bsn and the basis public_task
- **AND** reload the page
- **THEN** the Personal data block SHALL show naw, bsn and public_task

#### Scenario: A code outside the register is refused
@e2e exclude The verwerkingsregister lookup is an OpenRegister call; covered by the resolver unit test until the reference lands.

- **GIVEN** the type Bezwaar
- **WHEN** you set verwerkingsactiviteit to a code the register does not hold
- **THEN** the save SHALL fail with a message naming the code
