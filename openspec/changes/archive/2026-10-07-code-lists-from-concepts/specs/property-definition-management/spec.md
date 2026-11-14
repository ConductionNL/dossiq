## ADDED Requirements

### Requirement: A property takes its options from a concept scheme (REQ-PDM-03)

`propertyDefinition` SHALL carry an optional `conceptScheme` reference.
When set, the case data form SHALL offer the scheme's concepts as options
and store the chosen concept's URI; when empty, `enumValues` SHALL rule.
When both are set the scheme SHALL win and the authoring surface SHALL
warn.

#### Scenario: Options come from the scheme
@e2e tests/e2e/code-lists-from-concepts.spec.ts

- **GIVEN** a concept scheme Wijken with three concepts and a property bound to it
- **WHEN** you edit a case of a type with that property
- **THEN** the picker SHALL offer the three concepts

#### Scenario: Inline lists still work
@e2e tests/e2e/code-lists-from-concepts.spec.ts

- **GIVEN** a property with `enumValues` and no scheme
- **WHEN** you edit a case
- **THEN** the picker SHALL offer the inline values
