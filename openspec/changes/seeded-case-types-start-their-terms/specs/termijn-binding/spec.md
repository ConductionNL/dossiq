# termijn-binding delta: seeded-case-types-start-their-terms

## ADDED Requirements

### Requirement: A service norm never arms the statutory term (REQ-TERM-065)

The statutory term SHALL be started only from a definition of kind `statutory` (an
absent kind reads as statutory). A case type whose only definitions are of another kind
SHALL start no statutory term.

#### Scenario: A case type with only a planned definition gets no statutory term
@e2e exclude engine-internal selection; proven by tests/Unit/Service/Termijn/TermDefinitionsKindTest.php

- **GIVEN** a case type whose only active definition has `kind: planned`
- **WHEN** the statutory definition for it is asked for
- **THEN** none SHALL be answered

### Requirement: A planned definition binds the planned clock (REQ-TERM-066)

When a case is created and its case type declares no `plannedLeadTime`, the planned
clock SHALL be bound from the `planned` definition that resolves for the case, for its
`standardDurationDays`, counted in the mode the definition declares. A case type that
declares its own `plannedLeadTime` SHALL keep it.

#### Scenario: A case of an English demo type gets a planned end and no statutory term
@e2e exclude bound on the case-created event; proven by tests/Unit/Service/CaseTermsServicePlannedDefinitionTest.php and tests/Unit/Listener/DeadlineCaseCreatedListenerPlannedTest.php

- **GIVEN** a case type with no `plannedLeadTime` and a `planned` definition of 56 calendar days
- **WHEN** a case of that type is created
- **THEN** a term instance of kind `planned` SHALL be bound ending 56 calendar days after the start, rolled off a closed day

#### Scenario: The case type's own planned lead time wins
@e2e exclude engine-internal precedence; proven by tests/Unit/Service/CaseTermsServicePlannedDefinitionTest.php

- **GIVEN** a case type declaring `plannedLeadTime` of 10 days and a `planned` definition of 56 days
- **WHEN** a case of that type is created
- **THEN** the planned clock SHALL run 10 working days
