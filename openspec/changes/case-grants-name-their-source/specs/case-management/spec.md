## ADDED Requirements

### Requirement: A case shows who holds which right, and where the grant came from (REQ-CGP-01)

A case SHALL be able to show who holds which right on it and the source of
each grant, read from openregister's provenance. dossiq SHALL NOT compute
an effective permission, SHALL NOT store a copy of a grant, and SHALL NOT
summarise the provenance into a value of its own.

#### Scenario: an auditor asks who could open this dossier
@e2e tests/e2e/case-grants-name-their-source.spec.ts

- **GIVEN** a case whose grants come from a role, a group and a share
- **WHEN** an authorised reader opens its access panel
- **THEN** each holder SHALL be listed with the source of their grant

#### Scenario: dossiq computes nothing
@e2e exclude a structural scan of lib/, not a journey: the class whose existence it forbids has no page to open. tests/Unit/Architecture/NoSecondPermissionEvaluatorTest.php

- **GIVEN** the dossiq tree
- **WHEN** it is read for an effective-permission evaluator
- **THEN** none SHALL exist

### Requirement: A refusal names the rule that refused (REQ-CGP-02)

When an act on a case is refused, dossiq SHALL show the rule that refused
it, and SHALL NOT show only a status code. `CaseActionProvider` SHALL keep
answering which actions the calling user may take, and SHALL read
openregister's effective grants as well as its own guards.

#### Scenario: a handler is told why, not just no
@e2e tests/e2e/case-grants-name-their-source.spec.ts

- **GIVEN** a handler without the right to close a case
- **WHEN** they try to close it
- **THEN** the refusal SHALL name the rule that refused

#### Scenario: the action list still answers up front
@e2e tests/e2e/case-grants-name-their-source.spec.ts

- **GIVEN** a handler opening a case
- **WHEN** the case is returned
- **THEN** the actions they may take SHALL be returned with it
- **AND** an action they may not take SHALL NOT be offered

### Requirement: Case-type rights are declared per department, role and confidentiality (REQ-CGP-03)

A case type SHALL declare its rights as a matrix of department by role,
separately per confidentiality level. Confidentiality SHALL be a dimension
of the declaration and SHALL NOT be folded into a role name.

#### Scenario: a department may see the ordinary cases and not the confidential ones
@e2e tests/e2e/case-grants-name-their-source.spec.ts

- **GIVEN** a case type granting a department read at the ordinary level only
- **WHEN** a member of that department lists cases of that type
- **THEN** the confidential ones SHALL NOT be listed

#### Scenario: no role name carries a confidentiality level
@e2e exclude the declared role vocabulary is read from the shipped register definitions, which no browser reaches. tests/Unit/Settings/CaseTypeRightsMatrixTest.php

- **GIVEN** the declared roles
- **WHEN** their names are read
- **THEN** none SHALL encode a confidentiality level

### Requirement: A group of case types is granted once (REQ-CGP-04)

Case types SHALL be groupable, and a right SHALL be grantable on the group
so that every type in it inherits the grant. Inheritance SHALL be
openregister's, and dossiq SHALL NOT re-apply a group grant to each type.

#### Scenario: a samenwerkingsverband grants once
@e2e tests/e2e/case-grants-name-their-source.spec.ts

- **GIVEN** a group holding thirty case types and one grant on the group
- **WHEN** a member opens a case of any of them
- **THEN** the grant SHALL apply

#### Scenario: adding a case type to the group needs no second grant
@e2e tests/e2e/case-grants-name-their-source.spec.ts

- **GIVEN** a granted group
- **WHEN** a new case type joins it
- **THEN** the grant SHALL apply to the new type without a further act
