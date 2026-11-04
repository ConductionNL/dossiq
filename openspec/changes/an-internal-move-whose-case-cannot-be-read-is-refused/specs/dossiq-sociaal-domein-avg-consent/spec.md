## ADDED Requirements

### Requirement: An internal move whose consent rule cannot be read is refused (REQ-CST-01a)

A move of a case between teams of one organisation SHALL be refused when its case or its case type cannot be read. The refusal SHALL have the rule `consent-unchecked` and the sentence "Consent could not be checked, so the case was not handed on." This covers an empty case id, and a case or case type that is not found. It also covers a read that fails, and a store, register or case or case-type schema that is not configured. A case without a case type SHALL be refused the same way, because its rule cannot be checked.

#### Scenario: The case type cannot be read
@e2e exclude unit; CaseTransferConsentGateGoldenTest

- **GIVEN** a case whose case type cannot be read because the store fails
- **WHEN** the case moves from one team to another in the same organisation
- **THEN** the move SHALL be refused with the rule `consent-unchecked`
- **AND** the refusal SHALL say "Consent could not be checked, so the case was not handed on."

#### Scenario: The case schema is not configured
@e2e exclude unit; CaseTransferConsentGateGoldenTest

- **GIVEN** no `case_schema` is configured
- **WHEN** a case moves from one team to another in the same organisation
- **THEN** the move SHALL be refused the same way

#### Scenario: A case without a case type is refused
@e2e exclude unit; CaseTransferConsentGateGoldenTest

- **GIVEN** a case with no case type
- **WHEN** the case moves from one team to another in the same organisation
- **THEN** the move SHALL be refused with the rule `consent-unchecked`
- **AND** consent SHALL count as needed for that case
