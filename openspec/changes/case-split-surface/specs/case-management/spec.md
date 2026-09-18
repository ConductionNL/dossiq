## ADDED Requirements

### Requirement: A handler can split a case from its own page (REQ-CM-48)

The case page SHALL offer a split action that lists what the case holds, per
part the case type allows, and SHALL NOT offer a part the case type forbids.
Confirming SHALL open the second case, move the chosen rows onto it and write
the typed relation on both. A refusal SHALL reach the handler as the sentence
the policy wrote, naming what may still be divided.

#### Scenario: The picker offers only what may be divided
@e2e exclude the offer is computed server-side and asserted in tests/Unit/Service/Cases/CaseSplitPerformerTest.php::testThePickerIsOfferedOnlyThePartsTheCaseTypeAllows

- **GIVEN** a case type that allows parties and tasks but not documents
- **WHEN** the split is opened on a case of that type
- **THEN** the offer SHALL name parties and tasks and SHALL NOT name documents

#### Scenario: A chosen document moves and the rest stays
@e2e tests/e2e/case-split-surface.spec.ts

- **GIVEN** a case with three documents
- **WHEN** a handler splits it, choosing one
- **THEN** the new case SHALL hold that one
- **AND** the original SHALL hold the other two

#### Scenario: A row that is not on this case is refused by name
@e2e exclude a forged selection has no browser path; asserted in tests/Unit/Service/Cases/CaseSplitPerformerTest.php::testARowOnAnotherCaseIsRefusedByNameAndNotMoved

- **GIVEN** a selection naming a document that sits on another case
- **WHEN** the split is performed
- **THEN** that document SHALL NOT move
- **AND** the answer SHALL name it among the rows it refused

#### Scenario: A forbidden part is refused in the policy's own words
@e2e tests/e2e/case-split-surface.spec.ts

- **GIVEN** a case type that forbids dividing documents
- **WHEN** a handler confirms a split that includes one
- **THEN** the split SHALL be refused
- **AND** the message SHALL name what may still be divided
