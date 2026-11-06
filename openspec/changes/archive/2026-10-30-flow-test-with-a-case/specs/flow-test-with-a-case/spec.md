# flow-test-with-a-case Delta

## ADDED Requirements

### Requirement: The flow page tests a flow with a case

The flow page SHALL declare `testSubject` with the case schema of the dossiq register, the case title as the picker's
label and the button label "Test with a case" ("Testen met een zaak").

#### Scenario: DqFlow

- **GIVEN** the flow page of a case type's flow
- **THEN** the header offers "Testen met een zaak", which picks a case by its title
