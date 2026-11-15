# flow-test-with-a-case Specification

## Purpose
What dossiq does for flow test with a case. The requirements were first written by the change `flow-test-with-a-case` (archived 2026-10-30 under `openspec/changes/archive/2026-10-30-flow-test-with-a-case/`) and cover: The flow page tests a flow with a case.

## Requirements

### Requirement: The flow page tests a flow with a case

The flow page SHALL declare `testSubject` with the case schema of the dossiq register, the case title as the picker's
label and the button label "Test with a case" ("Testen met een zaak").

#### Scenario: DqFlow

- **GIVEN** the flow page of a case type's flow
- **THEN** the header offers "Testen met een zaak", which picks a case by its title
