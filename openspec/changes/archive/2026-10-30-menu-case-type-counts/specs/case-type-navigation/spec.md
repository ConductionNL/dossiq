## ADDED Requirements

### Requirement: REQ-CTN-006 — The picker says how many open cases each case type has

"Case types in my menu" SHALL show, beside every chosen case type and every
case type it offers to add, the number of open cases of that type the user may
see, as "{n} open cases" (nl "{n} open zaken"), as the board
`DqPersoonlijkeInstellingen` draws it.

The numbers SHALL come from one aggregate query per load: a terms facet on the
case's `caseType` over open cases, never one query per case type. A case is
open when it is not at a final status, not at a status hidden from lists and
not a draft. A case opened under an older version of a case type SHALL count
for the version in use. When the counts cannot be read, the picker SHALL show
no number rather than 0.

#### Scenario: Each case type shows its open cases from one query
@e2e exclude The query and the folding are asserted in MenuCaseTypesServiceTest with an ObjectService fake that counts its calls; the e2e instance's numbers move with every other spec's seeds.
- **GIVEN** three offered case types with 19, 7 and 0 open cases
- **WHEN** the user opens Case types in my menu
- **THEN** the rows and options SHALL say "19 open cases", "7 open cases" and "0 open cases"
- **AND** the server SHALL have asked OpenRegister one facet query and no count per case type

#### Scenario: A case on an older version counts for the version in use
@e2e exclude Asserted in MenuCaseTypesServiceTest.
- **GIVEN** a case type whose version 1 was superseded by version 2
- **AND** 3 open cases on version 1 and 2 on version 2
- **WHEN** the counts are read
- **THEN** the case type SHALL show 5 open cases

#### Scenario: An unreadable count shows no number
@e2e exclude The null is asserted in MenuCaseTypesServiceTest and its rendering in tests/vitest/menuCaseTypesSettings.spec.js.
- **GIVEN** OpenRegister cannot answer the facet
- **WHEN** the user opens Case types in my menu
- **THEN** no row and no option SHALL show a number
