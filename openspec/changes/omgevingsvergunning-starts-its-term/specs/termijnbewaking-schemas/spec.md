# termijnbewaking-schemas delta: omgevingsvergunning-starts-its-term

## ADDED Requirements

### Requirement: The shipped Omgevingsvergunning starts a statutory term (REQ-TERM-SCHEMA-003)

The app SHALL ship a TermijnDefinitie bound to the register's `omgevingsvergunning`
case type, with the duration and extension the case type declares (56 days, one
extension of 42 days, Ow 16.64), so a case of that type filed through the new case
form starts a statutory term.

#### Scenario: A new Omgevingsvergunning case gets a term
@e2e exclude the binding is a seed row read by slug; proven by tests/Unit/Repair/ShippedDeadlineDefinitionCoverageTest.php::testTheRegistersOmgevingsvergunningHasATermijnDefinitie

- **GIVEN** the shipped seeds
- **WHEN** a case of type `omgevingsvergunning` is created
- **THEN** a shipped TermijnDefinitie SHALL bind to `omgevingsvergunning` with 56 days and an extension capacity of 42 days
