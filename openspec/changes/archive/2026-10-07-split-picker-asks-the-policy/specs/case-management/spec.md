## ADDED Requirements

### Requirement: The picker says what may be divided before the handler chooses (REQ-CM-49)

The split picker SHALL ask the server which parts the case type allows before
it draws, and SHALL offer a part only when the case type allows it. It SHALL
NOT carry a copy of the rule. A case type that allows nothing and a case that
holds nothing of what it allows SHALL read as two different sentences.

#### Scenario: A case type that forbids dividing documents offers no documents

- **GIVEN** a case type whose `splittableParts` names parties and tasks
- **WHEN** the picker is opened on a case of that type
- **THEN** the answer SHALL name parties and tasks and SHALL NOT name documents
- @e2e exclude the offer is computed server-side; asserted in tests/Unit/Service/Cases/CaseSplitDivisiblePartsTest.php::testAForbiddenPartIsNotOffered

#### Scenario: A case type that declares nothing offers all three

- **GIVEN** a case type with no `splittableParts` declaration
- **WHEN** the picker is opened
- **THEN** all three parts SHALL be offered
- @e2e exclude asserted in tests/Unit/Service/Cases/CaseSplitDivisiblePartsTest.php::testACaseTypeThatDeclaresNothingOffersAllThree

#### Scenario: An unreadable case type does not invent a restriction

- **GIVEN** a case whose case type cannot be read
- **WHEN** the picker is opened
- **THEN** all three parts SHALL be offered rather than none
- @e2e exclude a broken reference cannot be staged from a browser; asserted in tests/Unit/Service/Cases/CaseSplitDivisiblePartsTest.php::testAnUnreadableCaseTypeOffersAllThree

#### Scenario: An empty case is not told its case type is the problem

- **GIVEN** a case type that allows all three parts and a case holding none of them
- **WHEN** the picker is opened
- **THEN** it SHALL say the case holds nothing to divide
- **AND** it SHALL NOT say the case type forbids dividing anything
- @e2e exclude two rendered sentences over one manifest-mounted dialog; asserted in tests/vitest/caseSplitPicker.spec.js
